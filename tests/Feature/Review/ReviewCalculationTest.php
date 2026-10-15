<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Enums\ChainRole;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Review\Services\ReviewAwareCalculationService;
use App\Domain\Review\Services\ReviewDpcService;
use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Support\Carbon;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * Review DPC — calculation under the original's rules, and full reuse of the
 * M6/M7 pipeline (PPT S38–S44) — M8 Phase 2.
 *
 * The milestone's second guarantee, after "never touch the original": **a
 * Review re-runs the original's rules, not today's.** A Review raised to fix a
 * mis-typed CR must not also silently re-decide eligibility under thresholds
 * that did not exist when the DPC was held.
 */
class ReviewCalculationTest extends TestCase
{
    use BuildsProceedings;
    use CompletesDpcProposals;
    use SeatsDpcCommittees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();

        // US3's five most recent grades sum to 14 — one short of the Class-I
        // threshold of 15, which is what makes the rule-version tests bite.
        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $this->officer(3, 'US3', $this->stCategoryId(),
            [2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
    }

    // ----------------------------------------------------------------- helpers

    private function reviews(): ReviewDpcService
    {
        return app(ReviewDpcService::class);
    }

    private function concludeOriginal(): void
    {
        $this->calculate();
        app(ResultApprovalService::class)->approve(
            $this->proceeding->fresh(), $this->memberOne, request(), 2017,
        );
    }

    private function raise(): DpcProposal
    {
        return $this->reviews()->create(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-08-20'),
            [['review_reason_id' => ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail()->id]],
        );
    }

    /**
     * Take a Review through the **full M6 pipeline** — approve, allot, accept,
     * schedule, open — exactly as a Fresh DPC does (AQ-12).
     */
    private function conductReview(DpcProposal $review): DpcProceeding
    {
        // TD-23, closed: this used to `forceFill` the Review straight to
        // Approved, skipping M4's submission gate and the AH's signature — the
        // one row of design §13's pipeline that was never actually driven.
        //
        // **That bypass hid a P1.** `sanctioned_post_count` was not copied onto
        // a Review, so M4's completeness gate refused every Review DPC in the
        // real application, and no test noticed because no test submitted one.
        // Driving the real path is the only thing that would have caught it,
        // and is the only thing that keeps it caught.
        $service = app(DpcProposalService::class);

        // A Review arrives with its chart copied from the original, so this
        // fills only what the copy legitimately leaves empty.
        $this->makeProposalSubmittable($review->fresh(), $this->authorizedOfficer);

        $service->submit($review->fresh(), $this->authorizedOfficer, request());
        $service->approve($review->fresh(), $this->administrativeHead, request());

        // The DPC code is minted by `approve()`, not asserted into place here.
        $review = $review->fresh();
        $this->assertSame(DpcProposalStatus::Approved, $review->status);
        $this->assertNotNull($review->dpc_code, 'approve() must mint the Review its own DPC code');
        $this->assertNotNull($review->signature_id, 'the AH approval must carry a signature');

        /*
         | Seated before allotting: a DPC is allotted to a serving member of its own
         | committee (business, 2026-09-06), and the qualification is committee
         | membership rather than a Commission role (correction, 2026-09-07).
         */
        $this->seatCommittee($this->proposal);

        app(AllotmentService::class)->allot(
            $review->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-09-10'),
        );
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($review->fresh(), $this->memberOne, request());
        $scheduling->schedule($review->fresh(), $this->memberOne, request(), Carbon::parse('2026-09-12'), '11:00');

        return app(ProceedingService::class)->open($review->fresh(), $this->memberOne, request());
    }

    private function calculateFor(DpcProceeding $proceeding): DpcCalculationRun
    {
        app(ProceedingCalculationService::class)->calculate($proceeding->fresh(), $this->memberOne);

        return DpcCalculationRun::query()
            ->where('dpc_proposal_id', $proceeding->dpc_proposal_id)
            ->latest('id')
            ->firstOrFail();
    }

    // ------------------------------------------------- original rule reuse

    public function test_a_review_calculates_under_the_originals_rule_version(): void
    {
        // AQ-11. The rules move on; the correction does not.
        $this->concludeOriginal();
        $originalRun = $this->proceeding->fresh()->finalCalculationRun;
        $review = $this->raise();

        config()->set('dpc.scoring.rule_version', '2030.9');

        $run = $this->calculateFor($this->conductReview($review));

        $this->assertSame($originalRun->rule_version, $run->rule_version);
        $this->assertNotSame('2030.9', $run->rule_version);
    }

    public function test_a_review_ignores_a_threshold_changed_since_the_original(): void
    {
        // The case that matters: a clerical correction must not become a fresh
        // eligibility decision under rules that did not exist at the time.
        $this->concludeOriginal();
        $review = $this->raise();

        // A later notification lowers the Class-I threshold to 14. US3 scored
        // 14 and was refused under the original's rules; that must not change.
        $scores = config('dpc.scoring.qualifying_scores');
        $scores['I_to_I'] = 14;
        config()->set('dpc.scoring.qualifying_scores', $scores);

        $run = $this->calculateFor($this->conductReview($review));

        $us3 = $run->results()->where('officer_name', 'US3')->firstOrFail();
        $this->assertSame(14, $us3->score_breakdown['total']);
        $this->assertFalse($us3->is_eligible);
    }

    public function test_the_stored_snapshot_is_the_originals_not_todays(): void
    {
        $this->concludeOriginal();
        $originalRun = $this->proceeding->fresh()->finalCalculationRun;
        $review = $this->raise();

        config()->set('dpc.scoring.tribal_area_bonus', 7);

        $run = $this->calculateFor($this->conductReview($review));

        $this->assertSame(
            $originalRun->configuration_snapshot,
            $run->configuration_snapshot,
        );
        $this->assertNotSame(7, $run->configuration_snapshot['tribal_area_bonus']);
    }

    public function test_configuration_is_restored_after_the_calculation(): void
    {
        // Configuration is process-global. A calculation that left it holding
        // a 2017 rule set would silently corrupt the next one.
        $this->concludeOriginal();
        $review = $this->raise();

        config()->set('dpc.scoring.rule_version', '2030.9');
        $this->calculateFor($this->conductReview($review));

        $this->assertSame('2030.9', config('dpc.scoring.rule_version'));
    }

    public function test_the_engine_version_stays_todays_across_the_override(): void
    {
        // The rules are the original's; the engine doing the work is this
        // build. Conflating them would misattribute the calculation.
        $this->concludeOriginal();
        $review = $this->raise();

        $proceeding = $this->conductReview($review);
        $this->calculateFor($proceeding);

        $this->assertSame(
            (string) config('dpc.scoring.engine_version'),
            $proceeding->fresh()->calculation_engine_version,
        );
    }

    public function test_a_fresh_dpc_still_uses_todays_rules(): void
    {
        // The decorator must be invisible to everything that is not a Review.
        config()->set('dpc.scoring.rule_version', '2030.9');

        $this->calculate();

        $this->assertSame('2030.9', $this->proceeding->fresh()->finalCalculationRun?->rule_version
            ?? DpcCalculationRun::query()->latest('id')->firstOrFail()->rule_version);
    }

    public function test_the_calculation_service_is_the_review_aware_decorator(): void
    {
        $this->assertInstanceOf(
            ReviewAwareCalculationService::class,
            app(ProceedingCalculationService::class),
        );
    }

    // ------------------------------------------------- full pipeline reuse

    public function test_a_review_runs_the_full_m6_and_m7_pipeline(): void
    {
        // AQ-12: a Review is allotted, accepted and scheduled like any DPC.
        // The member who conducted the original may have left the Commission,
        // which is why the cycle runs again rather than being inherited.
        $this->concludeOriginal();
        $review = $this->raise();

        $proceeding = $this->conductReview($review);
        $run = $this->calculateFor($proceeding);

        app(ResultApprovalService::class)->approve(
            $proceeding->fresh(), $this->memberOne, request(), 2017,
        );

        $fresh = $proceeding->fresh();
        $this->assertSame(DpcProceedingStatus::ResultApproved, $fresh->status);
        $this->assertSame($run->id, $fresh->final_calculation_run_id);
        $this->assertSame(2, $run->officers_considered);
    }

    public function test_a_correction_in_the_review_changes_its_own_result_only(): void
    {
        $this->concludeOriginal();
        $originalRun = $this->proceeding->fresh()->finalCalculationRun;
        $review = $this->raise();

        // Fix the mis-typed CR the Review was raised for: US3's 2013 ख -> क.
        $reviewRow = $review->evaluationRows->firstWhere('sn', 3);
        $reviewRow->crGrades()->where('year', 2013)->update(['grade' => 'क']);

        $run = $this->calculateFor($this->conductReview($review->fresh()));

        $this->assertSame(15, $run->results()->where('officer_name', 'US3')->firstOrFail()
            ->score_breakdown['total']);
        // The original's own result is untouched.
        $this->assertSame(14, $originalRun->results()->where('officer_name', 'US3')->firstOrFail()
            ->score_breakdown['total']);
    }

    public function test_the_reviews_calculation_is_a_separate_run(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $run = $this->calculateFor($this->conductReview($review));

        $this->assertSame($review->id, $run->dpc_proposal_id);
        $this->assertNotSame(
            $this->proceeding->fresh()->final_calculation_run_id,
            $run->id,
        );
    }

    // ------------------------------------------------------ chain metadata

    public function test_the_link_records_the_originals_version(): void
    {
        // Architect Directive 1.
        $this->concludeOriginal();
        $this->raise();

        $link = DpcReviewLink::query()->firstOrFail();

        $this->assertSame(
            $this->proceeding->fresh()->proposal_version,
            $link->original_proposal_version,
        );
        $this->assertSame(64, strlen((string) $link->original_proposal_version));
    }

    public function test_chain_depth_is_persisted_not_derived(): void
    {
        // Architect Directive 2. The original counts as depth 1.
        $this->concludeOriginal();
        $this->raise();

        $this->assertSame(2, DpcReviewLink::query()->firstOrFail()->chain_depth);
    }

    public function test_chain_depth_increases_with_each_review(): void
    {
        $this->concludeOriginal();
        $first = $this->raise();
        $proceeding = $this->conductReview($first);
        $this->calculateFor($proceeding);
        app(ResultApprovalService::class)->approve($proceeding->fresh(), $this->memberOne, request(), 2017);

        $second = $this->reviews()->create(
            $first->fresh(), $this->authorizedOfficer, Carbon::parse('2026-10-01'),
            [['review_reason_id' => ReviewReason::query()->where('code', 'wrong_order')->firstOrFail()->id]],
        );

        $links = DpcReviewLink::query()->orderBy('review_number')->get();
        $this->assertSame(2, $links[0]->chain_depth);
        $this->assertSame(3, $links[1]->chain_depth);
        $this->assertSame($links[0]->chain_uuid, $links[1]->chain_uuid);
        $this->assertNotNull($second->id);
    }

    // ------------------------------------------------------------ chain roles

    public function test_a_single_review_chain_reads_original_then_active(): void
    {
        // Architect Directive 4.
        $this->concludeOriginal();
        $review = $this->raise();

        $chain = $this->reviews()->chain($review->fresh());

        $this->assertSame(ChainRole::Original, $chain[0]['role']);
        $this->assertSame(ChainRole::ActiveReview, $chain[1]['role']);
        $this->assertTrue($chain[1]['role']->isOperative());
        $this->assertFalse($chain[0]['role']->isOperative());
    }

    public function test_an_earlier_review_becomes_superseded_not_original(): void
    {
        // The distinction that matters: a superseded Review and the Original
        // are both "not operative", but they are not the same thing.
        $this->concludeOriginal();
        $first = $this->raise();
        $proceeding = $this->conductReview($first);
        $this->calculateFor($proceeding);
        app(ResultApprovalService::class)->approve($proceeding->fresh(), $this->memberOne, request(), 2017);

        $this->reviews()->create(
            $first->fresh(), $this->authorizedOfficer, Carbon::parse('2026-10-01'),
            [['review_reason_id' => ReviewReason::query()->where('code', 'wrong_order')->firstOrFail()->id]],
        );

        $chain = $this->reviews()->chain($first->fresh());

        $this->assertSame(ChainRole::Original, $chain[0]['role']);
        $this->assertSame(ChainRole::SupersededReview, $chain[1]['role']);
        $this->assertSame(ChainRole::ActiveReview, $chain[2]['role']);
        $this->assertSame(ChainRole::SupersededReview, $this->reviews()->roleOf($first->fresh()));
    }

    public function test_exactly_one_node_in_a_chain_is_operative(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $operative = collect($this->reviews()->chain($review->fresh()))
            ->filter(fn (array $e): bool => $e['role']->isOperative());

        $this->assertCount(1, $operative);
    }

    // -------------------------------------------------------------- timeline

    public function test_the_review_created_event_carries_the_chain_metadata(): void
    {
        // Architect Directive 3 — the event exists and is replayable.
        $this->concludeOriginal();
        $this->raise();

        $p = ActivityTimeline::query()->where('event', 'dpc_review.created')->firstOrFail()->properties;

        $this->assertSame(1, $p['review_number']);
        $this->assertSame(2, $p['chain_depth']);
        $this->assertNotEmpty($p['chain_uuid']);
        $this->assertNotEmpty($p['original_proposal_version']);
        $this->assertSame('DPC-2026-HE-0001', $p['original_dpc_code']);
    }

    public function test_the_reviews_calculation_appears_on_its_own_timeline(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();
        $proceeding = $this->conductReview($review);
        $this->calculateFor($proceeding);

        $entry = ActivityTimeline::query()
            ->where('event', 'dpc_proceeding.calculate')
            ->where('subject_id', $review->id)
            ->firstOrFail();

        $this->assertSame($proceeding->fresh()->uuid, $entry->properties['proceeding_uuid']);
    }
}
