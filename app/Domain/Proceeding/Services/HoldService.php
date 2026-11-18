<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Proceeding\Enums\HoldReason;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Held promotions (`CIR`) — PPT S45, Conventional deck C3 — M7 Phase 4.
 *
 * A hold reserves the **promotion action** for an officer who nonetheless
 * takes part in the DPC. It is orthogonal to merit: S33's US8 scores 20,
 * qualifies for a Super Seat, and is held anyway on a missing 2016
 * immovable-property return. That independence is exactly why holds live here
 * and not inside the frozen scoring engine (architect ruling AQ-4).
 *
 * **Detection reads the effective chart**, so a correction made at the sitting
 * — a CR grade supplied, a property return confirmed — clears a hold on the
 * next detection, without touching M4 data.
 *
 * M7 marks a hold. **M9 resolves it.**
 */
class HoldService
{
    public function __construct(
        private readonly ProceedingCorrectionService $corrections,
        private readonly ActivityTimelineService $timeline,
        private readonly PromotionCrRules $promotionRules,
    ) {}

    /**
     * Re-derive the automatic holds for a sitting from the effective chart.
     *
     * Automatic holds are **replaced** on each run rather than accumulated:
     * they are a statement about the chart as it stands, and a hold whose cause
     * has been corrected must stop being in force. Manual holds are never
     * touched — those are the Commission's decision, not the rule's.
     *
     * @return Collection<int, DpcProceedingHold>
     */
    public function detect(DpcProceeding $proceeding, User $actor): Collection
    {
        $this->assertOpen($proceeding);

        if (! (bool) config('dpc.proceeding.hold.enabled', true)) {
            return collect();
        }

        $minCrYears = (int) config('dpc.proceeding.hold.min_cr_years', 5);
        $propertyRequired = (bool) config('dpc.proceeding.hold.property_required', true);

        $chart = $this->corrections->effectiveChart($proceeding);

        /*
         | Under the Class Master rule (2026-11-05) a hold is the CIR the calculation
         | gives: a CR or Property Return year the rule makes mandatory, missing and
         | justified with its reason and document. An unexplained gap is a rule
         | failure, not a hold, and a year the rule does not make mandatory holds
         | nothing. Without a rule the configured thresholds apply, as before.
         */
        $proposal = $proceeding->proposal;
        $rule = $proposal === null ? null : $this->promotionRules->ruleForProposal($proposal);
        $dpcYear = $proposal === null ? 0 : (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
        $ruleYears = $rule === null ? null : [
            'cr_count' => (int) $rule->cr_years,
            'cr' => $this->promotionRules->mandatoryCrYears($rule, $dpcYear),
            'property' => $this->promotionRules->mandatoryPropertyYears($rule, $dpcYear),
        ];

        return DB::transaction(function () use ($proceeding, $actor, $chart, $minCrYears, $propertyRequired, $ruleYears): Collection {
            $recorded = collect();

            foreach ($chart as $entry) {
                $sn = (int) $entry['sn'];
                $name = (string) $entry['row']->name;

                /** @var array<int, array<string, mixed>> $crCells */
                $crCells = $entry['cr'];
                /** @var array<int, array<string, mixed>> $propertyCells */
                $propertyCells = $entry['property'];

                $gradesPresent = 0;
                foreach ($crCells as $cell) {
                    if ($this->present($cell['effective'] === null ? null : (string) $cell['effective'])) {
                        $gradesPresent++;
                    }
                }

                $missingProperty = [];
                foreach ($propertyCells as $year => $cell) {
                    if ($cell['effective'] !== 'Y') {
                        $missingProperty[] = $year;
                    }
                }

                $found = [];

                if ($ruleYears !== null) {
                    $found = $this->ruleHolds($entry['row'], $crCells, $propertyCells, $ruleYears);
                } elseif ($gradesPresent < $minCrYears) {
                    $found[HoldReason::CrYearsMissing->value] = $gradesPresent.' of '
                        .$minCrYears.' required CR years recorded';
                }

                if ($ruleYears === null && $propertyRequired && $missingProperty !== []) {
                    $found[HoldReason::PropertyMissing->value] = 'Not recorded for '
                        .implode(', ', $missingProperty);
                }

                $recorded = $recorded->merge(
                    $this->reconcile($proceeding, $actor, $sn, $name, $entry['row']->id, $found)
                );
            }

            return $recorded;
        });
    }

    /**
     * The holds the Class Master rule gives an officer (2026-11-05), on the same
     * grounds the calculation gives CIR: fewer CRs than the rule's count with the
     * shortfall justified, a mandatory CR year missing and justified, or a mandatory
     * Property Return year missing and justified.
     *
     * @param  array<int, array<string, mixed>>  $crCells
     * @param  array<int, array<string, mixed>>  $propertyCells
     * @param  array{cr_count: int, cr: list<int>, property: list<int>}  $ruleYears
     * @return array<string, string> reason => detail
     */
    private function ruleHolds(DpcEvaluationRow $row, array $crCells, array $propertyCells, array $ruleYears): array
    {
        $justified = fn (string $kind, int $year): bool => $row->recordGaps
            ->contains(fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === $kind && $gap->year === $year && $gap->isJustified()
                // A reason the Administrator marks Unfit holds nothing (MoM point 12).
                && $gap->reason?->makes_unfit !== true);

        // The officer's own CR window, as the rule check and the calculation take it
        // (2026-11-16): a skipped middle year is replaced by an older one and holds
        // nothing; the year just before the DPC year has a ground of its own below.
        $details = app(EmployeeDetailsService::class);
        $window = $details->crYearWindow($row);
        $lastYear = $window === [] ? null : max($window);
        $counted = $row->crGrades->filter(fn ($cr): bool => in_array((int) $cr->year, $window, true) && $cr->put_up === true && $cr->has_grading !== false)->count();
        $graded = max($counted, count(array_filter($crCells, fn (array $cell): bool => $this->present($cell['effective'] === null ? null : (string) $cell['effective']))));
        // Appointed inside the window under a rule that says so (2026-11-18): nothing up
        // to that year is asked for, so it holds nothing and the count is what is left.
        $appointed = $row->appointedYearIsFit() ? (int) $row->appointedYear() : null;
        $justifiedCr = $row->recordGaps->filter(fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === DpcEvaluationRecordGap::KIND_CR && $gap->isJustified() && (int) $gap->year !== $lastYear
            && ($appointed === null || (int) $gap->year > $appointed))->pluck('year')->all();
        $crCount = $appointed === null || $lastYear === null ? $ruleYears['cr_count'] : min($ruleYears['cr_count'], max(0, $lastYear - $appointed));
        $shortfall = $crCount - $graded;

        $cr = array_values(array_filter($details->ruleCrYears($row), fn (int $year): bool => $year !== $lastYear
            && ! $row->crGrades->contains(fn ($grade): bool => (int) $grade->year === $year && $grade->put_up === true)
            && $justified(DpcEvaluationRecordGap::KIND_CR, $year)));

        if ($shortfall > 0 && count($justifiedCr) >= $shortfall) {
            $cr = array_values(array_unique([...$cr, ...array_map('intval', $justifiedCr)]));
            sort($cr);
        }
        $property = array_values(array_filter($ruleYears['property'], fn (int $year): bool => ($appointed === null || $year > $appointed) && ($propertyCells[$year]['effective'] ?? null) !== 'Y'
            && $justified(DpcEvaluationRecordGap::KIND_PROPERTY, $year)));

        $found = [];

        if ($cr !== []) {
            $found[HoldReason::CrYearsMissing->value] = $graded.' of '.$ruleYears['cr_count'].' required CR years recorded; missing and justified: '.implode(', ', $cr);
        }

        // The latest CR (the year before the DPC year) missing and justified: held for a
        // Circulation DPC (MoM of 16 November 2026, point 11).
        if ($lastYear !== null
            && $row->crGrades->contains(fn ($grade): bool => (int) $grade->year === $lastYear && $grade->put_up === false)
            && $justified(DpcEvaluationRecordGap::KIND_CR, $lastYear)) {
            $found[HoldReason::LatestCrMissing->value] = 'CR '.$lastYear.' (the year before the DPC year) not available, justified: Circulation DPC';
        }

        if ($property !== []) {
            $found[HoldReason::PropertyMissing->value] = 'Mandatory Property File not available for '.implode(', ', $property).' (justified)';
        }

        return $found;
    }

    /**
     * The Commission records a hold of its own.
     */
    public function record(
        DpcProceeding $proceeding,
        User $actor,
        int $sn,
        string $detail,
    ): DpcProceedingHold {
        $this->assertOpen($proceeding);

        $row = collect($this->corrections->effectiveChart($proceeding))
            ->firstWhere('sn', $sn);

        if ($row === null) {
            throw new WorkflowException('No officer with that serial number is on this chart.');
        }

        $existing = $this->existing($proceeding, $sn, HoldReason::Manual);

        if ($existing !== null) {
            throw new WorkflowException('This officer is already held by the Commission.');
        }

        return DB::transaction(function () use ($proceeding, $actor, $sn, $row, $detail): DpcProceedingHold {
            $hold = $this->create($proceeding, $actor, $sn, (string) $row['row']->name, $row['row']->id, HoldReason::Manual, $detail, false);

            $this->recordEvent($proceeding, $actor, 'dpc_proceeding.hold', $hold);

            return $hold;
        });
    }

    /**
     * Release a hold the Commission recorded by hand.
     *
     * Automatic holds are not released this way — they are re-derived, and an
     * automatic hold released by hand would simply reappear on the next
     * detection, which is worse than refusing.
     */
    public function release(DpcProceeding $proceeding, User $actor, DpcProceedingHold $hold): DpcProceedingHold
    {
        $this->assertOpen($proceeding);

        if ($hold->dpc_proceeding_id !== $proceeding->id) {
            throw new WorkflowException('That hold belongs to a different proceeding.');
        }

        if ($hold->released_at !== null) {
            throw new WorkflowException('That hold has already been released.');
        }

        if ($hold->is_automatic) {
            throw new WorkflowException(
                'This hold was determined from the chart. Correct the underlying entry and calculate again; '
                .'it will clear itself.'
            );
        }

        return DB::transaction(function () use ($proceeding, $actor, $hold): DpcProceedingHold {
            $hold->update(['released_at' => now()]);

            $this->recordEvent($proceeding, $actor, 'dpc_proceeding.hold_released', $hold->fresh());

            return $hold->fresh();
        });
    }

    /**
     * Holds in force for a sitting, keyed by serial number.
     *
     * @return Collection<int|string, EloquentCollection<int, DpcProceedingHold>>
     */
    public function activeBySn(DpcProceeding $proceeding): Collection
    {
        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->active()
            ->orderBy('sn')
            ->get()
            ->groupBy('sn');
    }

    public function isHeld(DpcProceeding $proceeding, int $sn): bool
    {
        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('sn', $sn)
            ->active()
            ->exists();
    }

    // ------------------------------------------------------------- internals

    /**
     * Bring the automatic holds for one officer into line with what the chart
     * now says: record what is newly true, release what no longer is.
     *
     * @param  array<string, string>  $found
     * @return Collection<int, DpcProceedingHold>
     */
    private function reconcile(
        DpcProceeding $proceeding,
        User $actor,
        int $sn,
        string $name,
        ?int $rowId,
        array $found,
    ): Collection {
        $recorded = collect();

        foreach ([HoldReason::CrYearsMissing, HoldReason::LatestCrMissing, HoldReason::PropertyMissing] as $reason) {
            $existing = $this->existing($proceeding, $sn, $reason);
            $applies = array_key_exists($reason->value, $found);

            if ($applies && $existing === null) {
                $hold = $this->create($proceeding, $actor, $sn, $name, $rowId, $reason, $found[$reason->value], true);
                $this->recordEvent($proceeding, $actor, 'dpc_proceeding.hold', $hold);
                $recorded->push($hold);

                continue;
            }

            if (! $applies && $existing !== null) {
                $existing->update(['released_at' => now()]);
                $this->recordEvent($proceeding, $actor, 'dpc_proceeding.hold_released', $existing->fresh());
            }
        }

        return $recorded;
    }

    private function existing(DpcProceeding $proceeding, int $sn, HoldReason $reason): ?DpcProceedingHold
    {
        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('sn', $sn)
            ->where('reason_code', $reason->value)
            ->active()
            ->first();
    }

    private function create(
        DpcProceeding $proceeding,
        User $actor,
        int $sn,
        string $name,
        ?int $rowId,
        HoldReason $reason,
        string $detail,
        bool $automatic,
    ): DpcProceedingHold {
        // A released hold for the same officer and reason may already exist —
        // the unique index covers (proceeding, sn, reason), so the released row
        // is updated back into force rather than duplicated. Its own history
        // is preserved in the timeline either way.
        $existing = DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('sn', $sn)
            ->where('reason_code', $reason->value)
            ->first();

        if ($existing !== null) {
            $existing->update(['released_at' => null]);

            return $existing->fresh();
        }

        return DpcProceedingHold::query()->create([
            'dpc_proceeding_id' => $proceeding->id,
            'dpc_evaluation_row_id' => $rowId,
            'sn' => $sn,
            'officer_name' => $name,
            'reason_code' => $reason->value,
            'detail' => $detail,
            'is_automatic' => $automatic,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
            'recorded_at' => now(),
        ]);
    }

    private function recordEvent(
        DpcProceeding $proceeding,
        User $actor,
        string $event,
        DpcProceedingHold $hold,
    ): void {
        $this->timeline->record(
            $event,
            $proceeding->proposal,
            ($event === 'dpc_proceeding.hold' ? 'Promotion held: ' : 'Hold released: ').$hold->officer_name,
            array_merge(
                ['proceeding_uuid' => $proceeding->uuid],
                $hold->toTimelineProperties(),
                $hold->released_at === null ? [] : ['released_at' => $hold->released_at->toIso8601String()],
            ),
            $actor,
        );
    }

    /**
     * A CR grade counts as present only if it has a value. `null` means the
     * year exists on the chart with nothing recorded against it — which is the
     * very thing a hold is about (see `CorrectableField::CrGrade`).
     */
    private function present(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function assertOpen(DpcProceeding $proceeding): void
    {
        if (! $proceeding->isOpen()) {
            throw new WorkflowException('This proceeding has concluded; holds can no longer be changed.');
        }
    }
}
