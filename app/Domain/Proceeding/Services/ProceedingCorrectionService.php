<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingOverride;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commission corrections during a sitting (PPT S33 EDIT / TRIBAL) — M7 Phase 2.
 *
 * **The seam that keeps M4 frozen.** A correction is recorded as an
 * append-only override; `dpc_evaluation_rows` is read and never written
 * (architect ruling AQ-1). `effectiveChart()` then assembles the department's
 * submission with corrections applied, which is what the workspace displays
 * and — from Phase 3 — what the frozen M5 engine will be handed. The engine
 * neither knows nor cares that its input came from a proceeding.
 *
 * A correction is **not a workflow transition**: it changes no state, and the
 * DPC is in exactly the same place before and after. Modelling it as one would
 * have the ledger claim a status change that did not occur, so corrections are
 * recorded on the timeline directly (architect Directive 4 — declare
 * transitions only when they exist).
 */
class ProceedingCorrectionService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * S33 — record a correction.
     *
     * Any correction already in force for the same officer, field and year is
     * **superseded, never overwritten**: the earlier value, its reason and its
     * author survive, because the question a tribunal asks is not only what the
     * value became but what it was and who changed it.
     */
    public function record(
        DpcProceeding $proceeding,
        User $actor,
        CorrectableField $field,
        int $sn,
        ?int $year,
        ?string $newValue,
        string $reason,
    ): DpcProceedingOverride {
        $this->assertOpen($proceeding);
        $this->assertFieldEnabled($field);
        $this->assertYearShape($field, $year);

        $row = $this->rowFor($proceeding, $sn);
        $previous = $this->effectiveValue($proceeding, $row, $field, $year);

        if ($this->normalise($previous) === $this->normalise($newValue)) {
            throw new WorkflowException('That is already the value in force; nothing has been changed.');
        }

        return DB::transaction(function () use ($proceeding, $actor, $field, $sn, $year, $newValue, $reason, $row, $previous): DpcProceedingOverride {
            $superseded = $this->inForceFor($proceeding, $sn, $field, $year);

            $override = DpcProceedingOverride::query()->create([
                'dpc_proceeding_id' => $proceeding->id,
                'sequence' => $this->nextSequence($proceeding),
                'dpc_evaluation_row_id' => $row->id,
                'sn' => $sn,
                'officer_name' => $row->name,
                'field' => $field->value,
                'year' => $year,
                'previous_value' => $previous,
                // The department's own entry, not the value this correction
                // replaces — after a chain those differ, and both are needed.
                'submitted_value' => $this->submittedValue($row, $field, $year),
                'new_value' => $newValue,
                'reason' => $reason,
                'applied_by' => $actor->id,
                'applied_by_name' => $actor->name,
                'applied_at' => now(),
            ]);

            $superseded?->update(['superseded_by_id' => $override->id]);

            $this->timeline->record(
                'dpc_proceeding.correction',
                $proceeding->proposal,
                "Correction recorded: {$field->label()} for {$row->name}",
                array_merge(
                    // Directive 3 — the proceeding UUID appears on every event.
                    ['proceeding_uuid' => $proceeding->uuid],
                    $override->toTimelineProperties(),
                    $superseded === null ? [] : ['superseded_override_uuid' => $superseded->uuid],
                ),
                $actor,
            );

            return $override;
        });
    }

    /**
     * S33 — withdraw a correction.
     *
     * Withdrawal is itself a correction: a new override restores the value the
     * withdrawn one replaced, and supersedes it. Nothing is deleted, and the
     * withdrawal carries its own reason and author — an "undo" that erased the
     * original would leave the record showing a value nobody ever chose.
     */
    public function withdraw(
        DpcProceeding $proceeding,
        User $actor,
        DpcProceedingOverride $override,
        string $reason,
    ): DpcProceedingOverride {
        $this->assertOpen($proceeding);

        if ($override->dpc_proceeding_id !== $proceeding->id) {
            throw new WorkflowException('That correction belongs to a different proceeding.');
        }

        if ($override->superseded_by_id !== null) {
            throw new WorkflowException('That correction has already been superseded.');
        }

        return DB::transaction(function () use ($proceeding, $actor, $override, $reason): DpcProceedingOverride {
            $reverting = DpcProceedingOverride::query()->create([
                'dpc_proceeding_id' => $proceeding->id,
                'sequence' => $this->nextSequence($proceeding),
                'dpc_evaluation_row_id' => $override->dpc_evaluation_row_id,
                'sn' => $override->sn,
                'officer_name' => $override->officer_name,
                'field' => $override->field,
                'year' => $override->year,
                'previous_value' => $override->new_value,
                'submitted_value' => $override->submitted_value,
                'new_value' => $override->previous_value,
                'reason' => $reason,
                'applied_by' => $actor->id,
                'applied_by_name' => $actor->name,
                'applied_at' => now(),
            ]);

            $override->update(['superseded_by_id' => $reverting->id]);

            $this->timeline->record(
                'dpc_proceeding.correction_withdrawn',
                $proceeding->proposal,
                "Correction withdrawn for {$override->officer_name}",
                array_merge(
                    ['proceeding_uuid' => $proceeding->uuid, 'withdrawn_override_uuid' => $override->uuid],
                    $reverting->toTimelineProperties(),
                ),
                $actor,
            );

            return $reverting;
        });
    }

    /**
     * The corrections currently in force, keyed `sn|field|year`.
     *
     * @return Collection<string, DpcProceedingOverride>
     */
    public function inForce(DpcProceeding $proceeding): Collection
    {
        return DpcProceedingOverride::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->inForce()
            ->get()
            ->keyBy(fn (DpcProceedingOverride $o): string => $this->key($o->sn, $o->field, $o->year));
    }

    /**
     * The department's chart with corrections applied — one row per officer,
     * each value carrying both what the department submitted and what is in
     * force now.
     *
     * Assembled in **two queries**, never one per row: the chart is read on
     * every workspace render, and an N+1 here would grow with the Commission's
     * workload rather than with anything a test would catch.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveChart(DpcProceeding $proceeding): array
    {
        $proceeding->loadMissing([
            'proposal.evaluationRows.reservationCategory',
            'proposal.evaluationRows.crGrades',
            'proposal.evaluationRows.loyaltyFlags',
            'proposal.evaluationRows.propertyFlags',
        ]);

        $overrides = $this->inForce($proceeding);

        return $proceeding->proposal->evaluationRows
            ->sortBy('sn')
            ->values()
            ->map(function (DpcEvaluationRow $row) use ($overrides): array {
                $cell = function (CorrectableField $field, ?int $year, ?string $submitted) use ($row, $overrides): array {
                    $override = $overrides->get($this->key($row->sn, $field->value, $year));

                    return [
                        'submitted' => $submitted,
                        'effective' => $override === null ? $submitted : $override->new_value,
                        'corrected' => $override !== null,
                        'override' => $override,
                    ];
                };

                $grades = $row->crGrades->keyBy('year');
                $loyalty = $row->loyaltyFlags->keyBy('year');
                $property = $row->propertyFlags->keyBy('year');

                return [
                    'row' => $row,
                    'sn' => $row->sn,
                    'tribal' => $cell(
                        CorrectableField::TribalAreaServiceYears,
                        null,
                        $row->tribal_area_service_years === null ? null : (string) $row->tribal_area_service_years,
                    ),
                    'cr' => $grades->mapWithKeys(fn ($g): array => [
                        $g->year => $cell(CorrectableField::CrGrade, $g->year, $g->grade),
                    ])->all(),
                    'loyalty' => $loyalty->mapWithKeys(fn ($f): array => [
                        $f->year => $cell(CorrectableField::LoyaltyFlag, $f->year, $this->flagToString($f->flag)),
                    ])->all(),
                    'property' => $property->mapWithKeys(fn ($f): array => [
                        $f->year => $cell(CorrectableField::PropertyFlag, $f->year, $this->flagToString($f->flag)),
                    ])->all(),
                ];
            })
            ->all();
    }

    /**
     * Correction history, read **only** from timeline records (architect
     * Directive 2 — the timeline is the canonical workflow history, and the
     * lifecycle is never reconstructed by joining business tables).
     *
     * This method touches no override row. If the timeline could not answer
     * the question it would visibly fail rather than quietly fall back.
     *
     * @return Collection<int, ActivityTimeline>
     */
    public function historyFromTimeline(DpcProceeding $proceeding): Collection
    {
        return ActivityTimeline::query()
            ->where('subject_type', $proceeding->proposal->getMorphClass())
            ->where('subject_id', $proceeding->dpc_proposal_id)
            ->whereIn('event', ['dpc_proceeding.correction', 'dpc_proceeding.correction_withdrawn'])
            ->where('properties->proceeding_uuid', $proceeding->uuid)
            // Second granularity plus more than one row per action, so the
            // UUID is the tie-break that makes the order deterministic.
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();
    }

    // ------------------------------------------------------------- internals

    /**
     * The next correction number within this proceeding (architect
     * Directive 1). Taken under the same transaction as the insert, and the
     * `(proceeding, sequence)` unique index is the backstop: if two
     * corrections ever raced to the same number, the second would fail loudly
     * rather than quietly produce two corrections numbered 4.
     */
    private function nextSequence(DpcProceeding $proceeding): int
    {
        return (int) DpcProceedingOverride::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->lockForUpdate()
            ->max('sequence') + 1;
    }

    /**
     * The highest correction number recorded in this proceeding — the
     * high-water mark a calculation records so staleness is decided by
     * sequence rather than by second-granularity timestamps.
     */
    public function highWaterSequence(DpcProceeding $proceeding): int
    {
        return (int) DpcProceedingOverride::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->max('sequence');
    }

    /**
     * Directive 2 — repository filtering by officer, field and year. No screen
     * consumes these yet; they exist so the first caller that needs them is
     * not the one that has to write the query.
     *
     * @return Collection<int, DpcProceedingOverride>
     */
    public function filter(
        DpcProceeding $proceeding,
        ?int $sn = null,
        CorrectableField|string|null $field = null,
        ?int $year = null,
        bool $inForceOnly = false,
    ): Collection {
        return DpcProceedingOverride::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->when($sn !== null, fn ($q) => $q->forOfficer($sn))
            ->when($field !== null, fn ($q) => $q->forField($field))
            // A null `$year` means "do not filter by year" rather than
            // "filter for no year". Nothing is lost: the only field without a
            // year is tribal service, and filtering by that field already
            // selects exactly those rows.
            ->when($year !== null, fn ($q) => $q->forYear($year))
            ->when($inForceOnly, fn ($q) => $q->inForce())
            ->orderBy('sequence')
            ->get();
    }

    /**
     * The department's own entry for a cell, read from the frozen M4 row.
     */
    private function submittedValue(DpcEvaluationRow $row, CorrectableField $field, ?int $year): ?string
    {
        return match ($field) {
            CorrectableField::TribalAreaServiceYears => $row->tribal_area_service_years === null
                ? null
                : (string) $row->tribal_area_service_years,
            CorrectableField::CrGrade => $row->crGrades->firstWhere('year', $year)?->grade,
            CorrectableField::LoyaltyFlag => $this->flagToString($row->loyaltyFlags->firstWhere('year', $year)?->flag),
            CorrectableField::PropertyFlag => $this->flagToString($row->propertyFlags->firstWhere('year', $year)?->flag),
        };
    }

    private function key(int $sn, string $field, ?int $year): string
    {
        return $sn.'|'.$field.'|'.($year ?? '');
    }

    private function inForceFor(
        DpcProceeding $proceeding,
        int $sn,
        CorrectableField $field,
        ?int $year,
    ): ?DpcProceedingOverride {
        return DpcProceedingOverride::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('sn', $sn)
            ->where('field', $field->value)
            ->when($year === null, fn ($q) => $q->whereNull('year'), fn ($q) => $q->where('year', $year))
            ->inForce()
            ->latest('applied_at')
            ->first();
    }

    /**
     * What the value is right now — the department's entry unless a correction
     * is in force over it. Recording the *effective* previous value rather than
     * the submitted one is what lets a chain of corrections be read back
     * step by step.
     */
    private function effectiveValue(
        DpcProceeding $proceeding,
        DpcEvaluationRow $row,
        CorrectableField $field,
        ?int $year,
    ): ?string {
        $override = $this->inForceFor($proceeding, $row->sn, $field, $year);

        if ($override !== null) {
            return $override->new_value;
        }

        return match ($field) {
            CorrectableField::TribalAreaServiceYears => $row->tribal_area_service_years === null
                ? null
                : (string) $row->tribal_area_service_years,
            CorrectableField::CrGrade => $row->crGrades->firstWhere('year', $year)?->grade,
            CorrectableField::LoyaltyFlag => $this->flagToString($row->loyaltyFlags->firstWhere('year', $year)?->flag),
            CorrectableField::PropertyFlag => $this->flagToString($row->propertyFlags->firstWhere('year', $year)?->flag),
        };
    }

    private function rowFor(DpcProceeding $proceeding, int $sn): DpcEvaluationRow
    {
        $row = DpcEvaluationRow::query()
            ->where('dpc_proposal_id', $proceeding->dpc_proposal_id)
            ->where('sn', $sn)
            ->first();

        if ($row === null) {
            throw new WorkflowException('No officer with that serial number is on this chart.');
        }

        return $row;
    }

    private function assertOpen(DpcProceeding $proceeding): void
    {
        if (! $proceeding->isOpen()) {
            throw new WorkflowException(
                'This proceeding has concluded. A correction after conclusion is made through a Review DPC.'
            );
        }
    }

    private function assertFieldEnabled(CorrectableField $field): void
    {
        if (! in_array($field, CorrectableField::enabled(), true)) {
            throw new WorkflowException('That field cannot be corrected during a proceeding.');
        }
    }

    private function assertYearShape(CorrectableField $field, ?int $year): void
    {
        if ($field->isPerYear() && $year === null) {
            throw new WorkflowException('A year is required for this correction.');
        }

        if (! $field->isPerYear() && $year !== null) {
            throw new WorkflowException('This correction does not apply to a single year.');
        }
    }

    private function flagToString(?bool $flag): ?string
    {
        return $flag === null ? null : ($flag ? 'Y' : 'N');
    }

    /**
     * Null and empty string are the same absence for comparison purposes, so
     * "clearing an already-empty value" is correctly rejected as a no-op.
     */
    private function normalise(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
