<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcEvaluationYearFlag;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The Seniority List is the source of truth for **who** is evaluated (business
 * directive, 2026-08-22).
 *
 * **The problem this removes.** S16, S17 and S18 each carried the same ten
 * employee fields, so a department typed or uploaded the same officer three
 * times. Nothing reconciled them, which meant an officer could be `US4` with one
 * date of birth on the seniority list and another on the evaluation chart — and
 * the scoring engine would use the chart's. The chart decides promotions, so the
 * copy that was least verified was the one that counted.
 *
 * **The flow now.** Importing the Seniority List *projects* an evaluation row per
 * officer, with all ten identity fields copied across. The Authorized Officer
 * then supplies only what evaluation actually needs — CR grades, loyalty,
 * property, tribal-area service and a note — and cannot add, remove or rename an
 * officer.
 *
 * **Identity is still copied onto `dpc_evaluation_rows`, not read through a
 * relation, and that is deliberate.** Every officer table in this application is
 * a point-in-time snapshot with no live foreign key (the ten-field standard), so
 * that a historical proposal cannot change because a record was edited later. A
 * relation would have made the chart's identity follow the seniority list
 * forever, including after approval — losing exactly the property the snapshot
 * design exists to provide, and requiring a schema change to get there. Copying
 * keeps the evidence immutable and needs no migration.
 *
 * **What re-importing a seniority list does.** The candidate set is reconciled
 * in place. Officers still there keep their rows and everything recorded against
 * them; officers who are not lose their rows — see {@see keyOf()} for how an
 * officer is recognised across the two lists, which is the part worth reading.
 */
class EvaluationCandidateService
{
    /**
     * Temporary offset used while renumbering, so moving officers between seniority
     * numbers can never collide on `unique(dpc_proposal_id, sn)`. Seniority numbers
     * are validated to stay below it.
     */
    private const RENUMBER_OFFSET = 32768;

    /**
     * Identity fields a re-import may leave blank without erasing what the
     * Authorized Officer recorded on the employee's details: the simplified
     * Seniority List does not carry them.
     */
    private const KEPT_WHEN_LIST_IS_BLANK = [
        'reservation_category_id',
        'seat_reservation_category_id',
        'qualification',
        'mobile',
        'date_of_posting_on_promotion_to_post',
        'date_of_posting_on_promotion_from_post',
    ];

    /**
     * Rebuild the evaluation candidate list from the seniority list.
     *
     * Idempotent: running it twice with an unchanged seniority list leaves the
     * chart exactly as it was, evaluation data included. That matters because
     * {@see DpcProposalService::saveSeniorityEntries()} calls it on every save,
     * and an officer's CR grades must not evaporate because somebody corrected a
     * spelling on the seniority list.
     *
     * **Reconciled in place** (2026-10-17). An officer still on the list keeps
     * their row — and with it everything recorded against it: CR years and their
     * questionnaire, Property Returns, the PH questionnaire, missing-record
     * justifications and uploaded documents. Only the identity fields are
     * refreshed from the list. Before this the rows were deleted and recreated,
     * which cascaded away every missing-record justification on each save.
     *
     * An officer no longer on the list loses their row. Their documents are kept
     * (the link is nulled, not the record) and re-attach by Employee ID if the list
     * is corrected back.
     */
    public function project(DpcProposal $proposal): void
    {
        DB::transaction(function () use ($proposal): void {
            $entries = $proposal->seniorityEntries()->orderBy('seniority_number')->get()->values();
            $existing = $proposal->evaluationRows()->get();

            /** @var array<string, DpcEvaluationRow> $byKey */
            $byKey = [];

            foreach ($existing as $row) {
                $byKey[$this->keyOf($row->employee_number, (int) $row->sn, (string) $row->name)] ??= $row;
            }

            /** @var array<int, DpcEvaluationRow> $matched entry index => surviving row */
            $matched = [];

            foreach ($entries as $index => $entry) {
                $key = $this->keyOf($entry->employee_number, (int) $entry->seniority_number, (string) $entry->name);

                if (isset($byKey[$key])) {
                    $matched[$index] = $byKey[$key];
                    unset($byKey[$key]);
                }
            }

            $keptIds = array_map(static fn (DpcEvaluationRow $row): int => $row->id, $matched);

            foreach ($existing as $row) {
                if (! in_array($row->id, $keptIds, true)) {
                    $row->delete();
                }
            }

            // Out of the way first, so no renumbering can collide.
            foreach ($matched as $row) {
                $row->forceFill(['sn' => $row->sn + self::RENUMBER_OFFSET])->save();
            }

            foreach ($entries as $index => $entry) {
                $identity = $this->identityFrom($entry);
                $row = $matched[$index] ?? null;

                if ($row === null) {
                    $created = $proposal->evaluationRows()->create($identity);
                    $this->reattachDocuments($created);

                    continue;
                }

                foreach (self::KEPT_WHEN_LIST_IS_BLANK as $field) {
                    if ($identity[$field] === null) {
                        unset($identity[$field]);
                    }
                }

                $row->forceFill($identity)->save();
            }
        });
    }

    /**
     * Replace the evaluation data on the projected rows, leaving identity alone.
     *
     * The officer set is asserted first, so a payload that adds, drops or renames
     * an officer is refused before anything is written rather than silently
     * reconciled.
     *
     * Per-year values are synchronised by year rather than deleted and recreated,
     * so the details recorded on the employee's screen for a year the chart keeps
     * (the CR questionnaire, the IPR dates) survive a chart save. A year the chart
     * no longer carries is removed, as before.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function applyEvaluationData(DpcProposal $proposal, array $rows): void
    {
        $this->assertMatchesCandidates($proposal, $rows);

        DB::transaction(function () use ($proposal, $rows): void {
            $bySn = $proposal->evaluationRows()->get()->keyBy(fn (DpcEvaluationRow $row): int => (int) $row->sn);

            foreach ($rows as $incoming) {
                $row = $bySn->get((int) $incoming['sn']);

                if (! $row instanceof DpcEvaluationRow) {
                    continue; // Unreachable after assertMatchesCandidates().
                }

                $row->forceFill([
                    'tribal_area_service_years' => $incoming['tribal_area_service_years'] ?? null,
                    'note' => $incoming['note'] ?? null,
                ])->save();

                $this->syncCrGrades($row, $incoming['cr_grades'] ?? []);
                $this->syncFlags($row->loyaltyFlags(), $incoming['loyalty_flags'] ?? []);
                $this->syncFlags($row->propertyFlags(), $incoming['property_flags'] ?? []);
            }
        });
    }

    /**
     * How many officers the chart must carry: the approved Seniority List's
     * length.
     *
     * **The Seniority List rather than the Zone of Consideration**, even though
     * the directive words it as the zone. The two are equal wherever a zone
     * applies, because the list is already gated to match it — but a class pair
     * the deck gives no zone formula has no zone at all, and the chart still has
     * to match the list. Taking the count from the list is right in both cases;
     * taking it from the zone would leave the uncapped ones ungoverned.
     */
    public function expectedCount(DpcProposal $proposal): int
    {
        return $proposal->seniorityEntries()->count();
    }

    /**
     * @return array{expected: int, present: int, matches: bool, has_candidates: bool}
     */
    public function summary(DpcProposal $proposal): array
    {
        $expected = $this->expectedCount($proposal);
        $present = $proposal->evaluationRows()->count();

        return [
            'expected' => $expected,
            'present' => $present,
            'matches' => $expected > 0 && $expected === $present,
            'has_candidates' => $expected > 0,
        ];
    }

    /**
     * The one wording for a chart that does not match its seniority list.
     *
     * Shared by the Form Request, the Excel commit, the service layer and the
     * submission checklist, so the same fault reads the same way whichever route
     * an officer meets it on.
     */
    public function mismatchMessage(): string
    {
        return 'The Evaluation Chart must contain exactly the same officers as the approved Seniority List.';
    }

    /**
     * Refuse a payload whose officers are not exactly the seniority list's.
     *
     * Three distinct faults, reported distinctly, because "the list changed" is
     * not actionable on its own:
     *
     *   - a different number of rows;
     *   - the same number of rows but a different set of serial numbers;
     *   - the right officer with a different employee number, which is how an
     *     edited spreadsheet quietly reassigns somebody's confidential record.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws WorkflowException
     */
    public function assertMatchesCandidates(DpcProposal $proposal, array $rows): void
    {
        foreach ($this->violationsFor($proposal, $rows) as $violation) {
            throw new WorkflowException($violation);
        }
    }

    /**
     * The same check, as a list of messages, for the Form Request and the
     * submission checklist — neither of which wants an exception.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    public function violationsFor(DpcProposal $proposal, array $rows): array
    {
        $entries = $proposal->seniorityEntries()->get();

        if ($entries->isEmpty()) {
            return ['The Seniority List must be uploaded before the Evaluation Chart can be filled in.'];
        }

        $expected = $entries
            ->keyBy(fn (DpcSeniorityEntry $entry): int => (int) $entry->seniority_number)
            ->map(fn (DpcSeniorityEntry $entry): ?string => $entry->employee_number);

        $submitted = [];

        foreach ($rows as $row) {
            $submitted[(int) ($row['sn'] ?? 0)] = $row['employee_number'] ?? null;
        }

        if (count($submitted) !== $expected->count()) {
            return [$this->mismatchMessage()
                ." It carries {$expected->count()} officer(s) on the Seniority List and ".count($submitted).' in this chart.'];
        }

        $missing = array_diff($expected->keys()->all(), array_keys($submitted));
        $extra = array_diff(array_keys($submitted), $expected->keys()->all());

        if ($missing !== [] || $extra !== []) {
            $detail = [];

            if ($missing !== []) {
                $detail[] = 'missing S.N. '.implode(', ', $missing);
            }

            if ($extra !== []) {
                $detail[] = 'unexpected S.N. '.implode(', ', $extra);
            }

            return [$this->mismatchMessage().' ('.implode('; ', $detail).')'];
        }

        // Employee numbers: compared only where the Seniority List recorded one.
        // A blank on the list is not evidence of anything, and refusing a chart
        // for filling one in would punish a department for adding information.
        $renamed = [];

        foreach ($expected as $sn => $employeeNumber) {
            if ($employeeNumber === null || $employeeNumber === '') {
                continue;
            }

            $incoming = $submitted[$sn] ?? null;

            if ($incoming !== null && $incoming !== '' && (string) $incoming !== (string) $employeeNumber) {
                $renamed[] = "S.N. {$sn} (Seniority List has {$employeeNumber}, chart has {$incoming})";
            }
        }

        if ($renamed !== []) {
            return ['Employee numbers cannot be changed on the Evaluation Chart: '.implode('; ', $renamed).'.'];
        }

        return [];
    }

    /**
     * The ten identity fields, copied from the seniority entry.
     *
     * `sn` takes the seniority number, so the chart is in seniority order and
     * every downstream reference to "S.N. 4" means the same officer on both
     * screens. Before this it was a free-standing serial the department typed
     * again, and the two could disagree.
     *
     * @return array<string, mixed>
     */
    private function identityFrom(DpcSeniorityEntry $entry): array
    {
        return [
            'sn' => $entry->seniority_number,
            'employee_number' => $entry->employee_number,
            'name' => $entry->name,
            'dob' => $entry->dob,
            'qualification' => $entry->qualification,
            'promotion_to_post' => $entry->promotion_to_post,
            'promotion_from_post' => $entry->promotion_from_post,
            'reservation_category_id' => $entry->reservation_category_id,

            // Carried through the projection (business instruction, 2026-09-07). A
            // column recorded on the seniority list and dropped here would reach the
            // committee's own screen already lost.
            'seat_reservation_category_id' => $entry->seat_reservation_category_id,
            'mobile' => $entry->mobile,
            'date_of_joining_service' => $entry->date_of_joining_service,
            'date_of_posting_on_promotion_to_post' => $entry->date_of_posting_on_promotion_to_post,
            'date_of_posting_on_promotion_from_post' => $entry->date_of_posting_on_promotion_from_post,
        ];
    }

    /**
     * How an officer is recognised across two versions of the seniority list.
     *
     * **Employee number first, and the serial number only as a fallback.** A
     * serial number is a *position*, not a person: re-order the list, or remove
     * one officer from the middle of it, and S.N. 4 is somebody else. Carrying
     * evaluation data across on serial alone would move one officer's CR grades
     * onto another's row — the worst available outcome, because it is invisible
     * and it decides a promotion.
     *
     * Where a department records no employee number, `sn|name` is the best key
     * available. It is imperfect — two officers of the same name at the same
     * position are indistinguishable — but it fails towards *discarding* data
     * rather than misattributing it, because a changed name produces a fresh key
     * and an empty row the officer must fill in again.
     */
    private function keyOf(?string $employeeNumber, int $sn, string $name): string
    {
        if ($employeeNumber !== null && $employeeNumber !== '') {
            return 'emp:'.$employeeNumber;
        }

        return 'pos:'.$sn.'|'.mb_strtolower(trim($name));
    }

    /**
     * The chart's CR grades for one officer, synchronised by year.
     *
     * A year whose grade the chart changes loses its CR questionnaire (put up,
     * grading, reports): those answers described the old grade, and the employee's
     * details screen then shows the year as needing to be answered again.
     *
     * @param  array<int, array<string, mixed>>  $grades
     */
    private function syncCrGrades(DpcEvaluationRow $row, array $grades): void
    {
        $existing = $row->crGrades()->get()->keyBy('year');
        $years = [];

        foreach ($grades as $grade) {
            $year = (int) $grade['year'];
            $years[] = $year;
            $value = $grade['grade'] ?? null;

            // Item 18 of the change register of 2026-08-27: the tribal-area benefit
            // is attributed to the CR year it was served in, and never carried
            // forward. Null stays null — see DpcRowRules.
            $tribal = array_key_exists('tribal_area', $grade) ? $grade['tribal_area'] : null;

            $current = $existing->get($year);

            if ($current === null) {
                $row->crGrades()->create(['year' => $year, 'grade' => $value, 'tribal_area' => $tribal]);

                continue;
            }

            $attributes = ['grade' => $value, 'tribal_area' => $tribal];

            if ($current->grade !== $value) {
                $attributes += ['put_up' => null, 'has_grading' => null, 'reports' => null];
            }

            $current->forceFill($attributes)->save();
        }

        $row->crGrades()->whereNotIn('year', $years)->delete();
    }

    /**
     * @param  HasMany<covariant DpcEvaluationYearFlag, DpcEvaluationRow>  $relation
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function syncFlags(HasMany $relation, array $flags): void
    {
        $years = [];

        foreach ($flags as $flag) {
            $years[] = (int) $flag['year'];

            $relation->getQuery()->clone()->updateOrCreate(
                ['dpc_evaluation_row_id' => $relation->getParentKey(), 'year' => (int) $flag['year']],
                ['flag' => $flag['flag'] ?? null],
            );
        }

        $relation->getQuery()->clone()->whereNotIn('year', $years)->delete();
    }

    /**
     * Documents kept from an earlier version of the list find their officer again
     * by Employee ID.
     */
    private function reattachDocuments(DpcEvaluationRow $row): void
    {
        if ($row->employee_number === null || $row->employee_number === '') {
            return;
        }

        DpcEvaluationDocument::query()
            ->where('dpc_proposal_id', $row->dpc_proposal_id)
            ->whereNull('dpc_evaluation_row_id')
            ->where('employee_number', $row->employee_number)
            ->update(['dpc_evaluation_row_id' => $row->id]);
    }
}
