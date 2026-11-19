<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit of the CR and Property File "missing" reasons left active by the
 * correction defect fixed on 2026-11-19.
 *
 * Until then, deleting a CR / Property File year, or editing it to "put up" /
 * "submitted", left the year's missing reason in place — an "Appointed in this
 * year" chosen by mistake went on limiting the years asked for. The fix withdraws
 * the reason from now on; this finds what the defect left behind, and changes
 * nothing: no record is updated or deleted, no document touched, nothing repaired.
 *
 * Classification of an active reason (dpc_evaluation_record_gaps row):
 *
 *  - STALE_RECORD_AVAILABLE — the year's record exists and says the CR was put up /
 *    the Property File submitted, yet a missing reason is active. Left by an Edit.
 *  - ORPHAN_YEAR_REMOVED — the year has no record, and the timeline shows the year
 *    was removed after the reason was recorded. Left by a Delete.
 *  - REVIEW_NO_RECORD — the year has no record and no removal is on the timeline: a
 *    possible false positive (a reason recorded on the Missing Records screen, which
 *    needs no year row, or data older than the timeline). Shown apart; review by hand.
 *
 * A reason on a year whose record says "not put up" / "not submitted" is the normal
 * case and is not reported. Each line also says whether the reason is "Appointed in
 * this year", and the appointment boundary it sets for the officer.
 */
class AuditStaleRecordGapsCommand extends Command
{
    public const STALE_AVAILABLE = 'STALE_RECORD_AVAILABLE';

    public const ORPHAN_REMOVED = 'ORPHAN_YEAR_REMOVED';

    public const REVIEW = 'REVIEW_NO_RECORD';

    private const APPOINTED = 'newly_appointed';

    private const HEADERS = [
        'Classification', 'DPC', 'DPC status', 'S.N.', 'Employee ID', 'Officer', 'Record', 'Year', 'Reason',
        'Appointment reason', 'Officer appointment boundary', 'Sets the boundary', 'Record row', 'Removed at', 'Reason recorded at',
    ];

    protected $signature = 'dpc:audit-stale-record-gaps
        {--csv= : Also write the findings to this CSV file}
        {--proposal= : Only this DPC (id or DPC code)}
        {--chunk=500 : Reasons read per batch}';

    protected $description = 'READ-ONLY: list CR / Property File missing reasons left active after the year was removed or corrected (nothing is changed)';

    public function handle(): int
    {
        $this->line('<comment>Read-only audit: nothing is updated, deleted or repaired.</comment>');

        $chunk = max(50, (int) $this->option('chunk'));
        $proposalIds = $this->proposalFilter();
        if ($proposalIds === false) {
            return self::FAILURE;
        }

        $findings = [];
        $query = DpcEvaluationRecordGap::query()
            ->whereIn('kind', [DpcEvaluationRecordGap::KIND_CR, DpcEvaluationRecordGap::KIND_PROPERTY])
            ->when($proposalIds !== null, fn ($q) => $q->whereHas('evaluationRow', fn ($r) => $r->whereIn('dpc_proposal_id', $proposalIds)))
            ->with(['reason:id,code,name_en', 'evaluationRow:id,dpc_proposal_id,sn,employee_number,name', 'evaluationRow.proposal:id,dpc_code,status']);

        $query->chunkById($chunk, function (Collection $gaps) use (&$findings): void {
            foreach ($this->classify($gaps) as $finding) {
                $findings[] = $finding;
            }
        });

        $this->report($findings);

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null|false the DPC ids to audit, null for all, false when not found
     */
    private function proposalFilter(): array|null|false
    {
        $given = trim((string) $this->option('proposal'));
        if ($given === '') {
            return null;
        }

        $ids = DpcProposal::query()->where('dpc_code', $given)->orWhere('id', ctype_digit($given) ? (int) $given : 0)->pluck('id')->all();
        if ($ids === []) {
            $this->error("No DPC [{$given}].");

            return false;
        }

        return array_map('intval', $ids);
    }

    /**
     * One batch: the year records, the removals on the timeline and the appointment
     * boundaries are read in a few queries for the whole batch.
     *
     * @param  Collection<int, DpcEvaluationRecordGap>  $gaps
     * @return list<array<string, mixed>>
     */
    private function classify(Collection $gaps): array
    {
        $rowIds = $gaps->pluck('dpc_evaluation_row_id')->unique()->values()->all();
        $proposalIds = $gaps->map(fn (DpcEvaluationRecordGap $gap) => $gap->evaluationRow?->dpc_proposal_id)->filter()->unique()->values()->all();

        // row => year => put up (CR) / submitted (Property File)
        $cr = DB::table('dpc_evaluation_cr_grades')->whereIn('dpc_evaluation_row_id', $rowIds)->get(['dpc_evaluation_row_id', 'year', 'put_up'])
            ->mapToGroups(fn ($r) => [$r->dpc_evaluation_row_id => [(int) $r->year => $r->put_up === null ? null : (bool) $r->put_up]])
            ->map(fn (Collection $years) => $years->reduce(fn (array $carry, array $year) => $carry + $year, []));
        $property = DB::table('dpc_evaluation_property_flags')->whereIn('dpc_evaluation_row_id', $rowIds)->get(['dpc_evaluation_row_id', 'year', 'submitted_on'])
            ->mapToGroups(fn ($r) => [$r->dpc_evaluation_row_id => [(int) $r->year => $r->submitted_on !== null]])
            ->map(fn (Collection $years) => $years->reduce(fn (array $carry, array $year) => $carry + $year, []));

        // Every active "Appointed in this year" of these officers, for the boundary (all of them, not only this batch's).
        $boundaries = DB::table('dpc_evaluation_record_gaps as g')->join('record_reasons as r', 'r.id', '=', 'g.record_reason_id')
            ->whereIn('g.dpc_evaluation_row_id', $rowIds)->where('r.code', self::APPOINTED)
            ->groupBy('g.dpc_evaluation_row_id')->selectRaw('g.dpc_evaluation_row_id as row_id, max(g.year) as boundary')
            ->pluck('boundary', 'row_id')
            ->map(fn ($year) => (int) $year);

        // Year removals on the timeline: proposal => "sn|kind|year" => latest time.
        $removals = [];
        ActivityTimeline::query()
            ->where('subject_type', (new DpcProposal)->getMorphClass())
            ->whereIn('subject_id', $proposalIds)
            ->whereIn('event', ['dpc_proposal.employee_cr_year_removed', 'dpc_proposal.employee_property_year_removed'])
            ->orderBy('id')
            ->each(function (ActivityTimeline $event) use (&$removals): void {
                $kind = str_contains($event->event, 'property') ? DpcEvaluationRecordGap::KIND_PROPERTY : DpcEvaluationRecordGap::KIND_CR;
                $key = ($event->properties['sn'] ?? '').'|'.$kind.'|'.($event->properties['year'] ?? '');
                $removals[(int) $event->subject_id][$key] = $event->occurred_at;
            }, 500);

        $findings = [];
        foreach ($gaps as $gap) {
            $row = $gap->evaluationRow;
            if ($row === null) {
                continue;
            }

            $years = $gap->kind === DpcEvaluationRecordGap::KIND_PROPERTY ? ($property[$row->id] ?? []) : ($cr[$row->id] ?? []);
            $year = (int) $gap->year;
            $hasRow = array_key_exists($year, $years);
            /** @var Carbon|null $removedAt */
            $removedAt = $removals[(int) $row->dpc_proposal_id][$row->sn.'|'.$gap->kind.'|'.$year] ?? null;

            $classification = match (true) {
                $hasRow && $years[$year] === true => self::STALE_AVAILABLE,
                $hasRow => null, // put up = no / not submitted: the reason is the record's own
                $removedAt !== null && ($gap->created_at === null || $removedAt->gte($gap->created_at)) => self::ORPHAN_REMOVED,
                default => self::REVIEW,
            };

            if ($classification === null) {
                continue;
            }

            $appointed = $gap->reason?->code === self::APPOINTED;
            $boundary = $boundaries[$row->id] ?? null;

            $findings[] = [
                'Classification' => $classification,
                'DPC' => (string) ($row->proposal->dpc_code ?? '#'.$row->dpc_proposal_id),
                'DPC status' => (string) ($row->proposal?->status->value ?? '—'),
                'S.N.' => (int) $row->sn,
                'Employee ID' => (string) ($row->employee_number ?? '—'),
                'Officer' => (string) $row->name,
                'Record' => $gap->kind === DpcEvaluationRecordGap::KIND_PROPERTY ? 'Property File' : 'CR',
                'Year' => $year,
                'Reason' => (string) ($gap->reason->code ?? '—'),
                'Appointment reason' => $appointed ? 'yes' : 'no',
                'Officer appointment boundary' => $boundary ?? '—',
                'Sets the boundary' => $appointed && $boundary === $year ? 'yes' : 'no',
                'Record row' => $hasRow ? ($gap->kind === DpcEvaluationRecordGap::KIND_PROPERTY ? 'submitted' : 'put up') : 'none',
                'Removed at' => $removedAt?->format('Y-m-d H:i:s') ?? '—',
                'Reason recorded at' => $gap->created_at?->format('Y-m-d H:i:s') ?? '—',
            ];
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function report(array $findings): void
    {
        $order = [self::STALE_AVAILABLE => 0, self::ORPHAN_REMOVED => 1, self::REVIEW => 2];
        usort($findings, fn (array $a, array $b): int => [$order[$a['Classification']], $a['DPC'], $a['S.N.'], $a['Year']] <=> [$order[$b['Classification']], $b['DPC'], $b['S.N.'], $b['Year']]);

        $count = fn (string $class): int => count(array_filter($findings, fn (array $f): bool => $f['Classification'] === $class));
        $this->table(['Classification', 'Reasons', 'Meaning'], [
            [self::STALE_AVAILABLE, $count(self::STALE_AVAILABLE), 'Suspicious: the year is recorded available, a missing reason still active'],
            [self::ORPHAN_REMOVED, $count(self::ORPHAN_REMOVED), 'Suspicious: the year was removed after its reason, the reason still active'],
            [self::REVIEW, $count(self::REVIEW), 'Possible false positive: no year record and no removal on the timeline'],
        ]);
        $this->line('Of which "Appointed in this year" setting an officer\'s boundary: '
            .count(array_filter($findings, fn (array $f): bool => $f['Sets the boundary'] === 'yes')));

        if ($findings !== []) {
            $this->table(self::HEADERS, array_map(fn (array $f): array => array_values($f), $findings));
        }

        $csv = trim((string) $this->option('csv'));
        if ($csv !== '') {
            $handle = @fopen($csv, 'wb');
            if ($handle === false) {
                $this->error("Could not write [{$csv}].");

                return;
            }
            fputcsv($handle, self::HEADERS);
            foreach ($findings as $finding) {
                fputcsv($handle, array_map(fn ($value): string => $this->safeCell((string) $value), array_values($finding)));
            }
            fclose($handle);
            $this->info('CSV written: '.$csv);
        }
    }

    /** A cell a spreadsheet would read as a formula is written as text. */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
