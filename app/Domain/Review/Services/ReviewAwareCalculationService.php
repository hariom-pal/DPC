<?php

declare(strict_types=1);

namespace App\Domain\Review\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Review\Models\DpcReviewCalculation;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Support\Facades\Config;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * A Review DPC calculates under **the original's rules**, never today's
 * (architect ruling AQ-11) — M8 Phase 2.
 *
 * A Review may be conducted years after the DPC it corrects. Applying current
 * configuration would mean that a Review raised to fix a mis-typed CR also
 * **silently re-decided eligibility** under thresholds that did not exist when
 * the DPC was held — a clerical correction quietly becoming a fresh decision.
 *
 * **How, without touching frozen or completed code.** M5 stores the complete
 * rule set on every run, and `RuleSet::fromConfig()` and `fromSnapshot()` share
 * one `build()`, so a stored snapshot has exactly the shape of
 * `config('dpc.scoring')`. Restoring the original's rules is therefore
 * restoring the original's *configuration* — which is what AD-3 says business
 * rules are — for the duration of the call, and putting it back afterwards.
 *
 * This is a **decorator**, bound over `ProceedingCalculationService` in the
 * container. M7 is complete and its freeze verification must stay empty, so
 * the behaviour is added around it rather than inside it. A Fresh DPC passes
 * straight through to the parent, unchanged.
 */
class ReviewAwareCalculationService extends ProceedingCalculationService
{
    public function __construct(
        DpcCalculationService $engine,
        ProceedingCorrectionService $corrections,
        HoldService $holds,
        ActivityTimelineService $timeline,
        private readonly ReviewDpcService $reviews,
    ) {
        parent::__construct($engine, $corrections, $holds, $timeline);
    }

    public function calculate(DpcProceeding $proceeding, User $actor): DpcCalculationRun
    {
        $snapshot = $this->originalRules($proceeding);

        if ($snapshot === null) {
            return parent::calculate($proceeding, $actor);
        }

        $link = $this->reviews->linkForReview($proceeding->proposal);

        /** @var array<string, mixed> $live */
        $live = (array) Config::get('dpc.scoring');

        // The engine version is the build doing the work *now*, not the one
        // that ran the original — so it is preserved across the override while
        // every rule is replaced.
        Config::set('dpc.scoring', array_merge(
            ['engine_version' => $live['engine_version'] ?? null],
            $snapshot,
        ));

        try {
            $run = parent::calculate($proceeding, $actor);

            if ($link !== null) {
                $this->recordProvenance($link->id, $run, $snapshot, $proceeding);
            }

            return $run;
        } finally {
            // Restored whatever happens. Configuration is process-global, and
            // a failed calculation must not leave the next one running under
            // a DPC from 2017.
            Config::set('dpc.scoring', $live);
        }
    }

    /**
     * Architect Directives 1 and 2 — the configuration snapshot and the
     * original calculation this Review ran under, kept on the M8 side because
     * `dpc_calculation_runs` belongs to frozen M5.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function recordProvenance(
        int $linkId,
        DpcCalculationRun $run,
        array $snapshot,
        DpcProceeding $proceeding,
    ): void {
        DpcReviewCalculation::query()->create([
            'dpc_review_link_id' => $linkId,
            'dpc_calculation_run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'configuration_snapshot_uuid' => self::snapshotUuid($snapshot),
            'original_calculation_run_uuid' => $this->reviews
                ->originalCalculationRun($proceeding->proposal)?->uuid,
            'rule_version' => $run->rule_version,
            'calculation_engine_version' => $proceeding->fresh()?->calculation_engine_version,
            'calculated_at' => now(),
        ]);
    }

    /**
     * A UUID **derived from the snapshot's content**, not generated at random.
     *
     * Two calculations that ran under identical rules therefore carry the same
     * snapshot UUID, which makes "were these two results decided under the same
     * rules?" an equality check. A random UUID would have recorded only that a
     * snapshot existed — a fact nobody needs.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function snapshotUuid(array $snapshot): string
    {
        $canonical = $snapshot;
        self::sortDeep($canonical);

        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'dpc:rule-snapshot:'.hash('sha256', (string) json_encode($canonical)),
        );
    }

    /**
     * Key order must not change the identity of a rule set.
     *
     * @param  array<string, mixed>  $array
     */
    private static function sortDeep(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::sortDeep($value);
            }
        }
        unset($value);

        ksort($array);
    }

    /**
     * The rule snapshot this proceeding must run under, or null for a Fresh
     * DPC — and for a Review whose original has no pinned calculation, which
     * cannot happen through the UI but must not be guessed at if it does.
     *
     * @return array<string, mixed>|null
     */
    private function originalRules(DpcProceeding $proceeding): ?array
    {
        try {
            $run = $this->reviews->originalCalculationRun($proceeding->proposal);
        } catch (Throwable) {
            return null;
        }

        if ($run === null) {
            return null;
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = $run->configuration_snapshot;

        return $snapshot === [] ? null : $snapshot;
    }
}
