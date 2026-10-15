<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcVacancyCalculation;
use App\Domain\Dpc\ValueObjects\VacancyBreakdown;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording a vacancy calculation for audit (change register of 2026-08-27,
 * item 10).
 *
 * Separate from {@see VacancyEngine} on purpose: the engine is pure and can be
 * golden-tested without a database, and this is the only part that writes. It is
 * the same split as M5's — `ScoringService` computes, `DpcCalculationService`
 * persists — and for the same reason.
 *
 * **Recalculating supersedes; it never overwrites.** The previous row keeps every
 * figure it held and gains a successor, so "what was the vacancy when the
 * Commission acted" stays answerable after a master is edited. `DpcVacancyCalculation`
 * refuses any other update at the model layer, so the guarantee survives a caller
 * that forgets.
 */
class VacancyCalculationRecorder
{
    public function __construct(
        private readonly VacancyEngine $engine,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * The live calculation for a proposal, or null if none has been recorded.
     */
    public function current(DpcProposal $proposal): ?DpcVacancyCalculation
    {
        return DpcVacancyCalculation::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->current()
            ->latest('calculated_at')
            ->first();
    }

    /**
     * Calculate and record, superseding whatever was there.
     *
     * **Refuses an incomplete calculation rather than storing a partial one.** A row
     * with a null sanctioned strength would be a permanent record of a number
     * nobody produced, and the screen can already show the incomplete breakdown
     * without persisting it — `VacancyEngine::calculate()` returns it either way.
     * So the audit trail holds only calculations that actually resolved.
     */
    public function record(DpcProposal $proposal, ?User $actor = null): DpcVacancyCalculation
    {
        $breakdown = $this->engine->calculate($proposal);

        if (! $breakdown->isComplete()) {
            throw new RuntimeException($this->incompleteReason($breakdown));
        }

        return DB::transaction(function () use ($proposal, $breakdown, $actor): DpcVacancyCalculation {
            $previous = DpcVacancyCalculation::query()
                ->where('dpc_proposal_id', $proposal->id)
                ->current()
                ->lockForUpdate()
                ->get();

            $calculation = DpcVacancyCalculation::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'sanctioned_from_post_id' => $breakdown->sanctionedFromPostId,
                'sanctioned_strength' => $breakdown->sanctionedStrength,
                'recruitment_rule_id' => $breakdown->recruitmentRuleId,
                'promotion_percentage' => $breakdown->promotionPercentage,
                'period_from' => $breakdown->periodFrom->toDateString(),
                'period_to' => $breakdown->periodTo->toDateString(),
                'working_strength' => $breakdown->workingStrength,
                'base_vacancy' => $breakdown->baseVacancy,
                'retirement_vacancy' => $breakdown->retirementVacancy,
                'deputation_vacancy' => $breakdown->deputationVacancy,
                'total_vacancy' => $breakdown->totalVacancy,
                /*
                 | Kept as the *exact* figure the chair split produced, so the audit
                 | row still records what the rounding rule acted on. Since the
                 | business directive of 2026-09-02 that is the promotion chair
                 | count rather than a fraction of the total.
                 */
                'promotion_vacancy_exact' => $breakdown->promotionChairs,
                'promotion_vacancy' => $breakdown->promotionVacancy,
                'direct_recruitment_vacancy' => $breakdown->directRecruitmentVacancy,
                'rounding_rule' => $breakdown->roundingRule,
                'contributors' => $breakdown->contributors,
                'inputs' => $breakdown->toInputs(),
                'calculated_by' => $actor?->id,
                'calculated_at' => now(),
            ]);

            foreach ($previous as $superseded) {
                $superseded->forceFill(['superseded_by_id' => $calculation->id])->save();
            }

            /*
             | The timeline carries the figures, not a pointer to them.
             |
             | Workflow history must be reproducible from timeline records alone
             | (architect directive), so a reader reconstructing what happened does
             | not have to join back to a table whose rows may since have been
             | superseded.
             */
            $this->timeline->record(
                'dpc_proposal.vacancy_calculated',
                $proposal,
                sprintf(
                    'Vacancy calculated: %d sanctioned − %d working + %d retirement + %d deputation = %d total '
                    .'(%s%% promotion → %d promotion, %d direct recruitment)',
                    $breakdown->sanctionedStrength,
                    $breakdown->workingStrength,
                    $breakdown->retirementVacancy,
                    $breakdown->deputationVacancy,
                    $breakdown->totalVacancy,
                    $breakdown->promotionPercentage,
                    $breakdown->promotionVacancy,
                    $breakdown->directRecruitmentVacancy,
                ),
                $breakdown->toInputs() + [
                    'calculation_uuid' => $calculation->uuid,
                    'total_vacancy' => $breakdown->totalVacancy,
                    'promotion_vacancy' => $breakdown->promotionVacancy,
                    'direct_recruitment_vacancy' => $breakdown->directRecruitmentVacancy,
                    'superseded' => $previous->pluck('uuid')->all(),
                ],
                $actor,
            );

            return $calculation;
        });
    }

    /**
     * Which master is incomplete, named so the officer knows where to go.
     *
     * "Vacancy cannot be calculated" is not an actionable message; "the Department
     * Post Mapping for Deputy Director records no sanctioned strength" is.
     */
    private function incompleteReason(VacancyBreakdown $breakdown): string
    {
        if ($breakdown->sanctionedStrength === null) {
            return 'The Department Post Mapping for the Promotion To Post records no Sanctioned Strength, '
                .'so the vacancy cannot be calculated. Ask the CGPSC Administrator to record it under '
                .'Administration → Department Post Mapping.';
        }

        return 'No active Recruitment Rule states a promotion percentage for the Promotion To Post, so the '
            .'promotion and direct-recruitment split cannot be calculated. Ask the CGPSC Administrator to '
            .'register the rule under Administration → Recruitment Rules.';
    }
}
