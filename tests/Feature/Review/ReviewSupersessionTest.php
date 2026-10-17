<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Domain\Review\Models\DpcReviewCalculation;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Review\Services\ReviewAwareCalculationService;
use App\Domain\Review\Services\ReviewDpcService;
use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * Review DPC — supersession taking legal effect, Review documents, the
 * SUPERSEDED watermark and publication (PPT S44) — M8 Phase 3.
 *
 * Two guarantees are tested hardest here, and they pull in opposite
 * directions: a superseded document must be **visibly** superseded, and it must
 * remain **byte-identical** to what was issued.
 */
class ReviewSupersessionTest extends TestCase
{
    use BuildsProceedings;
    use SeatsDpcCommittees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();

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

    private function documents(): ProceedingDocumentService
    {
        return app(ProceedingDocumentService::class);
    }

    private function concludeOriginal(): void
    {
        $this->calculate();
        app(ResultApprovalService::class)->approve(
            $this->proceeding->fresh(), $this->memberOne, request(), 2017,
        );
        app(ProceedingDocumentService::class)->generate($this->proceeding->fresh(), $this->memberOne);
    }

    private function raise(): DpcProposal
    {
        return $this->reviews()->create(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-08-20'),
            [[
                'review_reason_id' => ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail()->id,
                'detail' => 'US3 CR for 2013 transcribed as ख instead of क.',
            ]],
        );
    }

    private function conductReview(DpcProposal $review): DpcProceeding
    {
        $review->forceFill([
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'DPC-2026-HE-0002',
            'forwarded_at' => now(),
            'approved_at' => now(),
        ])->saveQuietly();

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

        $proceeding = app(ProceedingService::class)->open($review->fresh(), $this->memberOne, request());
        app(ProceedingCalculationService::class)->calculate($proceeding->fresh(), $this->memberOne);

        return $proceeding->fresh();
    }

    private function approveReview(DpcProceeding $proceeding): DpcProceeding
    {
        return app(ResultApprovalService::class)
            ->approve($proceeding->fresh(), $this->memberOne, request(), 2017);
    }

    // ---------------------------------------------- supersession takes effect

    public function test_supersession_takes_effect_when_the_review_is_approved(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $this->assertNull(DpcReviewLink::query()->firstOrFail()->superseded_at);

        $this->approveReview($this->conductReview($review));

        $link = DpcReviewLink::query()->firstOrFail();
        $this->assertNotNull($link->superseded_at);
        $this->assertTrue($link->hasTakenEffect());
    }

    public function test_a_review_that_is_never_approved_leaves_the_original_standing(): void
    {
        // The timing is the whole point: supersession is a consequence of a
        // decision, not of an intention to review.
        $this->concludeOriginal();
        $review = $this->raise();
        $this->conductReview($review);

        $this->assertFalse($this->reviews()->isSuperseded($this->proposal->fresh()));
        $this->assertNull(DpcReviewLink::query()->firstOrFail()->superseded_at);
    }

    public function test_supersession_is_recorded_on_both_timelines(): void
    {
        $this->concludeOriginal();
        $this->approveReview($this->conductReview($this->raise()));

        $onReview = ActivityTimeline::query()
            ->where('event', 'dpc_review.supersession_effective')->firstOrFail();
        $onOriginal = ActivityTimeline::query()
            ->where('event', 'dpc_review.superseded')->firstOrFail();

        $this->assertSame($this->proposal->id, $onOriginal->subject_id);
        $this->assertSame('DPC-2026-HE-0001', $onOriginal->properties['original_dpc_code']);
        $this->assertSame('DPC-2026-HE-0002', $onOriginal->properties['review_dpc_code']);
        $this->assertNotEmpty($onReview->properties['superseded_at']);
        $this->assertSame(
            $onReview->properties['chain_uuid'],
            $onOriginal->properties['chain_uuid'],
        );
    }

    public function test_the_department_is_notified_that_its_dpc_is_superseded(): void
    {
        Event::fake([DpcSupersededByReview::class]);

        $this->concludeOriginal();
        $this->approveReview($this->conductReview($this->raise()));

        Event::assertDispatched(DpcSupersededByReview::class, function (DpcSupersededByReview $event): bool {
            return $event->originalDpcCode === 'DPC-2026-HE-0001'
                && $event->reviewDpcCode === 'DPC-2026-HE-0002'
                && $event->reviewNumber === 1
                && $event->departmentId === $this->department->id;
        });
    }

    public function test_approving_a_fresh_dpc_supersedes_nothing(): void
    {
        // The decorator must be invisible to anything that is not a Review.
        Event::fake([DpcSupersededByReview::class]);

        $this->concludeOriginal();

        Event::assertNotDispatched(DpcSupersededByReview::class);
        $this->assertSame(0, DpcReviewLink::query()->count());
    }

    public function test_supersession_cannot_be_applied_twice(): void
    {
        $this->concludeOriginal();
        $proceeding = $this->conductReview($this->raise());
        $this->approveReview($proceeding);

        $first = DpcReviewLink::query()->firstOrFail()->superseded_at;

        // A second approval is attempted for real. M7 refuses it; whatever the refusal
        // looks like, the supersession must not be applied again or move.
        Carbon::setTestNow(now()->addDay());

        try {
            $this->approveReview($proceeding);
        } catch (\Throwable) {
            // refused — expected
        }

        Carbon::setTestNow();

        $this->assertSame(
            $first->toIso8601String(),
            DpcReviewLink::query()->firstOrFail()->superseded_at->toIso8601String(),
        );
    }

    // -------------------------------------------------- calculation provenance

    public function test_every_review_calculation_records_its_snapshot_and_original_run(): void
    {
        // Architect Directives 1 and 2.
        $this->concludeOriginal();
        $originalRun = $this->proceeding->fresh()->finalCalculationRun;
        $review = $this->raise();

        $this->conductReview($review);

        $record = DpcReviewCalculation::query()->firstOrFail();

        $this->assertNotEmpty($record->configuration_snapshot_uuid);
        $this->assertSame($originalRun->uuid, $record->original_calculation_run_uuid);
        $this->assertSame($originalRun->rule_version, $record->rule_version);
        $this->assertNotEmpty($record->run_uuid);
    }

    public function test_the_snapshot_uuid_is_derived_from_the_rules_not_random(): void
    {
        // Identical rules must produce an identical UUID, so "were these two
        // decided under the same rules?" is an equality check.
        $snapshot = ['rule_version' => '2026.1', 'qualifying_scores' => ['I_to_I' => 15]];
        $reordered = ['qualifying_scores' => ['I_to_I' => 15], 'rule_version' => '2026.1'];

        $this->assertSame(
            ReviewAwareCalculationService::snapshotUuid($snapshot),
            ReviewAwareCalculationService::snapshotUuid($reordered),
        );
        $this->assertNotSame(
            ReviewAwareCalculationService::snapshotUuid($snapshot),
            ReviewAwareCalculationService::snapshotUuid(['rule_version' => '2030.9']),
        );
    }

    public function test_recalculating_a_review_records_a_second_provenance_row(): void
    {
        $this->concludeOriginal();
        $proceeding = $this->conductReview($this->raise());

        app(ProceedingCalculationService::class)->calculate($proceeding->fresh(), $this->memberOne);

        $records = DpcReviewCalculation::query()->get();
        $this->assertCount(2, $records);
        // Same rules both times, so the same snapshot UUID.
        $this->assertSame(
            $records[0]->configuration_snapshot_uuid,
            $records[1]->configuration_snapshot_uuid,
        );
    }

    // ------------------------------------------------------- review documents

    public function test_a_review_document_states_what_it_corrects(): void
    {
        // AQ-13 — all five facts.
        $this->concludeOriginal();
        $proceeding = $this->approveReview($this->conductReview($this->raise()));
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $document = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('type', DocumentType::PromotionList->value)
            ->firstOrFail();

        $html = $this->documents()->contents($document);

        $this->assertStringContainsString('Review DPC', $html);
        $this->assertStringContainsString('DPC-2026-HE-0001', $html);   // original DPC
        $this->assertStringContainsString('25/06/2026', $html);    // original date
        $this->assertStringContainsString('DPC-2026-HE-0002', $html);   // review DPC
        $this->assertStringContainsString('20/08/2026', $html);    // review date
        $this->assertStringContainsString('transcribed as', $html); // reason detail
    }

    public function test_a_fresh_dpcs_document_carries_no_review_context(): void
    {
        $this->concludeOriginal();

        $document = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $this->assertStringNotContainsString('Review DPC —', $this->documents()->contents($document));
    }

    // ---------------------------------------------------- superseded watermark

    public function test_a_superseded_document_is_served_with_a_watermark(): void
    {
        $this->concludeOriginal();
        $original = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $this->assertStringNotContainsString('SUPERSEDED', $this->documents()->contents($original));

        $this->approveReview($this->conductReview($this->raise()));

        $html = $this->documents()->contents($original->fresh());
        $this->assertStringContainsString('SUPERSEDED', $html);
        $this->assertStringContainsString('DPC-2026-HE-0002', $html);
    }

    public function test_the_stored_document_is_never_rewritten(): void
    {
        // The guarantee that pulls against the watermark: the artefact was
        // issued as it stands, and the hash proves a department's copy is the
        // copy the Commission gave it.
        $this->concludeOriginal();
        $original = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $storedBefore = $this->documents()->storedContents($original);
        $hashBefore = $original->content_hash;

        $this->approveReview($this->conductReview($this->raise()));

        $this->assertSame($storedBefore, $this->documents()->storedContents($original->fresh()));
        $this->assertSame($hashBefore, $original->fresh()->content_hash);
        $this->assertStringNotContainsString('SUPERSEDED', $storedBefore);
    }

    public function test_a_superseded_document_still_verifies(): void
    {
        // A watermark added on delivery must not make a valid document look
        // tampered with.
        $this->concludeOriginal();
        $original = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $this->approveReview($this->conductReview($this->raise()));

        $this->assertTrue($this->documents()->verify($original->fresh()));
    }

    public function test_a_superseded_document_remains_downloadable(): void
    {
        // Architect Directive 5: superseded documents remain available.
        $this->concludeOriginal();
        $original = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $this->approveReview($this->conductReview($this->raise()));

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.documents.show', [$this->proceeding, $original]))
            ->assertOk()
            ->assertSee('SUPERSEDED');
    }

    public function test_a_document_is_not_watermarked_while_the_review_is_unapproved(): void
    {
        $this->concludeOriginal();
        $original = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)->firstOrFail();

        $this->conductReview($this->raise());

        $this->assertStringNotContainsString('SUPERSEDED', $this->documents()->contents($original->fresh()));
    }

    // ------------------------------------------------------------ publication

    public function test_a_provisional_review_result_cannot_be_published(): void
    {
        // M7's Directive 5 applies to a Review exactly as it does to a Fresh
        // DPC. US3 is ST and scores 14 — between the relaxation floor and the
        // threshold — so BD-2/BD-3 flags the run and publication is refused.
        $this->concludeOriginal();
        $proceeding = $this->approveReview($this->conductReview($this->raise()));
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $this->assertTrue($proceeding->fresh()->finalCalculationRun->has_pending_decisions);

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.documents.publish', $proceeding))
            ->assertSessionHasErrors('document');

        $this->assertSame(2, DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNull('published_at')->count());
    }

    public function test_a_review_that_resolves_the_error_can_be_published(): void
    {
        // The Review corrects the very entry it was raised for: US3's 2013 CR
        // was transcribed as ख instead of क. Corrected, US3 scores 15, nothing
        // is provisional, and the result can be published.
        $this->concludeOriginal();
        $review = $this->raise();

        $review->evaluationRows->firstWhere('sn', 3)
            ->crGrades()->where('year', 2013)->update(['grade' => 'क']);

        $proceeding = $this->approveReview($this->conductReview($review->fresh()));
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $this->assertFalse($proceeding->fresh()->finalCalculationRun->has_pending_decisions);

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.documents.publish', $proceeding))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNull('published_at')->count());
    }

    public function test_the_chain_shows_the_review_as_operative_after_approval(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();
        $this->approveReview($this->conductReview($review));

        $chain = $this->reviews()->chain($review->fresh());

        $this->assertTrue($chain[1]['role']->isOperative());
        $this->assertNotNull($chain[1]['link']->superseded_at);
    }
}
