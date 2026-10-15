<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingOverride;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CALCULATE within a sitting (PPT S33) — M7 Phase 3.
 *
 * **The frozen M5 engine is called unchanged.** This service's whole job is to
 * present it with the *effective* chart — the department's submission with the
 * Commission's corrections applied — instead of the raw M4 rows.
 *
 * It does that by materialising corrected **in-memory copies** of the
 * evaluation rows and substituting them on the proposal's loaded relation
 * before handing it over. `DpcCalculationService::calculate()` opens with
 * `loadMissing()`, which leaves an already-loaded relation alone, so the engine
 * consumes the corrected rows without knowing they were corrected — and
 * without one line of M5 changing.
 *
 * **Nothing is persisted through those copies.** They are never saved, and a
 * test asserts the M4 row is byte-identical after a calculation. That is the
 * whole point: M4 stays frozen, and the calculation still reflects what the
 * Commission decided at the sitting.
 */
class ProceedingCalculationService
{
    public function __construct(
        private readonly DpcCalculationService $engine,
        private readonly ProceedingCorrectionService $corrections,
        private readonly HoldService $holds,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * S33 `CALCULATE`. Every execution creates a new run; nothing is ever
     * overwritten (M5's rule, which this milestone inherits rather than
     * relaxes). Recalculating after a correction is the normal path, not an
     * exception.
     */
    public function calculate(DpcProceeding $proceeding, User $actor): DpcCalculationRun
    {
        if (! $proceeding->isOpen()) {
            throw new WorkflowException(
                'This proceeding has concluded, so no further calculation can be made against it.'
            );
        }

        $proposal = $proceeding->proposal;
        $proposal->loadMissing([
            'promotionFromPost', 'promotionToPost',
            'evaluationRows.crGrades', 'evaluationRows.reservationCategory',
            'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
            'vacancies.reservationCategory',
        ]);

        $sequence = $this->corrections->highWaterSequence($proceeding);

        $this->applyCorrections($proceeding, $proposal);

        // Directive 4 — a first calculation and a recalculation are different
        // events. A recalculation supersedes a result the Commission may
        // already have looked at; a consumer that cannot tell them apart
        // cannot know that.
        $isRecalculation = $proceeding->latest_calculation_run_id !== null;
        $engineVersion = (string) config('dpc.scoring.engine_version');

        return DB::transaction(function () use ($proceeding, $proposal, $actor, $sequence, $isRecalculation, $engineVersion): DpcCalculationRun {
            $run = $this->engine->calculate($proposal, $actor);

            // Which run is current, and how much of the correction history it
            // saw. `final_calculation_run_id` is a different question — the run
            // the Commission attested to — and is pinned only at approval.
            $proceeding->update([
                'latest_calculation_run_id' => $run->id,
                'calculated_through_sequence' => $sequence,
                'calculation_engine_version' => $engineVersion,
            ]);

            // Holds are re-derived with every calculation: both read the same
            // effective chart, so a correction that clears a hold and one that
            // changes a score take effect together rather than drifting apart.
            $this->holds->detect($proceeding, $actor);

            $previousRunId = $proceeding->getOriginal('latest_calculation_run_id');

            $this->timeline->record(
                $isRecalculation ? 'dpc_proceeding.recalculated' : 'dpc_proceeding.calculate',
                $proposal,
                $isRecalculation ? 'Result recalculated' : 'Result calculated',
                [
                    'proceeding_uuid' => $proceeding->uuid,
                    'run_uuid' => $run->uuid,
                    'rule_version' => $run->rule_version,
                    // Directive 1 — which build of the engine produced this.
                    'calculation_engine_version' => $engineVersion,
                    'proposal_version' => $run->proposal_version,
                    'calculated_through_sequence' => $sequence,
                    'officers_considered' => $run->officers_considered,
                    'eligible_count' => $run->eligible_count,
                    'main_list_count' => $run->main_list_count,
                    'waiting_list_count' => $run->waiting_list_count,
                    // Directive 5 — an unresolved business rule is reported,
                    // never resolved here.
                    'has_pending_decisions' => $run->has_pending_decisions,
                    // The run this one supersedes, so a consumer can follow
                    // the chain without reading a business table.
                    'supersedes_run_uuid' => $isRecalculation && $previousRunId !== null
                        ? DpcCalculationRun::query()->find($previousRunId)?->uuid
                        : null,
                ],
                $actor,
            );

            return $run;
        });
    }

    /**
     * The run the Commission is currently looking at, or null.
     */
    public function latestRun(DpcProceeding $proceeding): ?DpcCalculationRun
    {
        if ($proceeding->latest_calculation_run_id === null) {
            return null;
        }

        return DpcCalculationRun::query()->find($proceeding->latest_calculation_run_id);
    }

    /**
     * Whether the current calculation predates a correction (architect
     * ruling AQ-2).
     *
     * Decided by **correction sequence, not by timestamp**. `applied_at` and
     * `executed_at` have second granularity, so a correction and a
     * recalculation in the same second would be indistinguishable — and the
     * failure would be silent, which is the worst property a check like this
     * can have.
     */
    public function isStale(DpcProceeding $proceeding): bool
    {
        if ($proceeding->latest_calculation_run_id === null) {
            return true;
        }

        return $this->corrections->highWaterSequence($proceeding)
            > (int) $proceeding->calculated_through_sequence;
    }

    /**
     * The corrections a stale calculation has not seen — so the screen can
     * name the officers affected rather than saying only that something is
     * out of date.
     *
     * @return Collection<int, DpcProceedingOverride>
     */
    public function correctionsSinceCalculation(DpcProceeding $proceeding)
    {
        return $this->corrections->filter($proceeding)
            ->where('sequence', '>', (int) $proceeding->calculated_through_sequence)
            ->values();
    }

    /**
     * Every run for this proceeding's proposal, newest first — the calculation
     * history. Runs are immutable, so this is a record of what was produced
     * and when, not a list of drafts.
     *
     * @return EloquentCollection<int, DpcCalculationRun>
     */
    public function history(DpcProceeding $proceeding): EloquentCollection
    {
        return DpcCalculationRun::query()
            ->where('dpc_proposal_id', $proceeding->dpc_proposal_id)
            ->with('executedBy')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Substitute corrected in-memory copies of the evaluation rows on the
     * proposal's loaded relation.
     *
     * Only the fields the frozen engine actually reads are applied — the CR
     * grades and tribal-area service. Loyalty and immovable-property
     * corrections are recorded and displayed, but they feed the **hold**
     * determination rather than the score, and holds are not part of this
     * phase.
     */
    private function applyCorrections(DpcProceeding $proceeding, DpcProposal $proposal): void
    {
        $overrides = $this->corrections->inForce($proceeding);

        if ($overrides->isEmpty()) {
            return;
        }

        $corrected = $proposal->evaluationRows->map(function (DpcEvaluationRow $row) use ($overrides): DpcEvaluationRow {
            // A replicated model carries the same attributes; the real `id` is
            // restored because the engine writes it onto every result row, and
            // a result that pointed at nothing would not survive review.
            $copy = $row->replicate();
            $copy->setRawAttributes($row->getRawOriginal());
            $copy->exists = true;
            $copy->setRelations($row->getRelations());

            $tribal = $overrides->get($row->sn.'|'.CorrectableField::TribalAreaServiceYears->value.'|');

            if ($tribal !== null) {
                $copy->tribal_area_service_years = $tribal->new_value === null ? null : (int) $tribal->new_value;
            }

            $grades = $row->crGrades->map(function ($grade) use ($overrides, $row) {
                $override = $overrides->get($row->sn.'|'.CorrectableField::CrGrade->value.'|'.$grade->year);

                if ($override === null) {
                    return $grade;
                }

                $gradeCopy = $grade->replicate();
                $gradeCopy->setRawAttributes($grade->getRawOriginal());
                $gradeCopy->exists = true;
                $gradeCopy->grade = $override->new_value;

                return $gradeCopy;
            });

            $copy->setRelation('crGrades', new EloquentCollection($grades->all()));

            return $copy;
        });

        $proposal->setRelation('evaluationRows', new EloquentCollection($corrected->all()));
    }
}
