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
use App\Domain\Review\Models\DpcDocumentReviewReference;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Review\Services\ReviewDpcService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * What a document permanently says about the correction it belongs to —
 * M8 Phase 3, architect Directives 1, 2 and 3 (issued with the Phase 3
 * approval).
 *
 * Three obligations, tested here rather than folded into the supersession
 * tests, because they are about the **document as a standalone artefact**: it
 * leaves the system, and years later it has to answer for itself.
 *
 * - **D1** — a superseded document names the DPC that superseded it.
 * - **D2** — the moment supersession took effect is in the document's metadata,
 *   not re-derived at read time.
 * - **D3** — four identifiers, permanently: original DPC, review DPC, chain,
 *   and the document itself.
 */
class ReviewDocumentReferenceTest extends TestCase
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
        $this->documents()->generate($this->proceeding->fresh(), $this->memberOne);
    }

    private function raise(DpcProposal $original, string $date = '2026-08-20'): DpcProposal
    {
        return $this->reviews()->create(
            $original->fresh(),
            $this->authorizedOfficer,
            Carbon::parse($date),
            [[
                'review_reason_id' => ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail()->id,
                'detail' => 'US3 CR for 2013 transcribed as ख instead of क.',
            ]],
        );
    }

    /**
     * Take a Review through allotment, scheduling, its sitting and its own
     * approval — the full pipeline, because a Review is an ordinary DPC (S35).
     */
    private function conclude(DpcProposal $review, string $code, string $date): DpcProceeding
    {
        $review->forceFill([
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => $code,
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
            $review->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse($date),
        );
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($review->fresh(), $this->memberOne, request());
        $scheduling->schedule(
            $review->fresh(), $this->memberOne, request(), Carbon::parse($date)->addDays(2), '11:00',
        );

        $proceeding = app(ProceedingService::class)->open($review->fresh(), $this->memberOne, request());
        app(ProceedingCalculationService::class)->calculate($proceeding->fresh(), $this->memberOne);

        return app(ResultApprovalService::class)
            ->approve($proceeding->fresh(), $this->memberOne, request(), 2017);
    }

    private function documentOf(DpcProceeding $proceeding): DpcProceedingDocument
    {
        return DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('type', DocumentType::PromotionList->value)
            ->orderByDesc('document_version')
            ->firstOrFail();
    }

    private function referenceOf(DpcProceedingDocument $document): DpcDocumentReviewReference
    {
        return DpcDocumentReviewReference::query()
            ->where('dpc_proceeding_document_id', $document->id)
            ->firstOrFail();
    }

    // ------------------------------------ D3 — the four permanent references

    public function test_a_review_document_records_all_four_identifiers(): void
    {
        $this->concludeOriginal();
        $review = $this->raise($this->proposal);
        $proceeding = $this->conclude($review, 'DPC-2026-HE-0002', '2026-09-10');
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $document = $this->documentOf($proceeding);
        $reference = $this->referenceOf($document);
        $link = DpcReviewLink::query()->firstOrFail();

        $this->assertSame($document->uuid, $reference->document_uuid);
        $this->assertSame($this->proposal->fresh()->uuid, $reference->original_dpc_uuid);
        $this->assertSame($review->fresh()->uuid, $reference->review_dpc_uuid);
        $this->assertSame($link->chain_uuid, $reference->chain_uuid);
        $this->assertSame(1, $reference->review_number);
    }

    public function test_the_rendered_review_document_prints_all_four_identifiers(): void
    {
        // The record is not enough on its own: a document that leaves the
        // system has to carry its own provenance on its face.
        $this->concludeOriginal();
        $review = $this->raise($this->proposal);
        $proceeding = $this->conclude($review, 'DPC-2026-HE-0002', '2026-09-10');
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $document = $this->documentOf($proceeding);
        $html = $this->documents()->contents($document);

        $this->assertStringContainsString((string) $this->proposal->fresh()->uuid, $html);
        $this->assertStringContainsString((string) $review->fresh()->uuid, $html);
        $this->assertStringContainsString(DpcReviewLink::query()->firstOrFail()->chain_uuid, $html);
        // Directive 3's fourth identifier — the document's own UUID, resolved
        // at render time from the row M7 creates before it renders into it.
        $this->assertStringContainsString($document->uuid, $html);
    }

    public function test_each_reissued_version_gets_its_own_reference(): void
    {
        // Re-issuing produces a new version rather than overwriting (M7's
        // rule), so each version references itself, not its predecessor.
        $this->concludeOriginal();
        $proceeding = $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $this->documents()->generate($proceeding->fresh(), $this->memberOne);
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $versions = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('type', DocumentType::PromotionList->value)
            ->orderBy('document_version')
            ->get();

        $this->assertCount(2, $versions);

        foreach ($versions as $version) {
            $this->assertSame($version->uuid, $this->referenceOf($version)->document_uuid);
        }
    }

    public function test_a_fresh_dpc_in_no_chain_has_no_review_reference(): void
    {
        // The decorator must stay invisible to a DPC that corrects nothing and
        // has not been corrected.
        $this->concludeOriginal();

        $this->assertSame(0, DpcDocumentReviewReference::query()->count());
    }

    // -------------------------------------------- D1 and D2 — on supersession

    public function test_supersession_stamps_the_effective_timestamp_into_document_metadata(): void
    {
        $this->concludeOriginal();
        $original = $this->documentOf($this->proceeding->fresh());

        $this->assertSame(0, DpcDocumentReviewReference::query()->count());

        $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $reference = $this->referenceOf($original);
        $link = DpcReviewLink::query()->firstOrFail();

        $this->assertTrue($reference->isSuperseded());
        $this->assertSame(
            $link->superseded_at->toIso8601String(),
            $reference->supersession_effective_at->toIso8601String(),
        );
        // Directive 3 on the superseded side too: the original's own document
        // carries the same four identifiers as the Review's.
        $this->assertSame($original->uuid, $reference->document_uuid);
        $this->assertSame($this->proposal->fresh()->uuid, $reference->original_dpc_uuid);
        $this->assertSame($link->review->uuid, $reference->review_dpc_uuid);
        $this->assertSame($link->chain_uuid, $reference->chain_uuid);
    }

    public function test_a_superseded_document_names_the_dpc_that_superseded_it(): void
    {
        // Directive 1. "SUPERSEDED" alone tells a reader their copy is stale
        // and gives them nowhere to go.
        $this->concludeOriginal();
        $original = $this->documentOf($this->proceeding->fresh());

        $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $html = $this->documents()->contents($original->fresh());

        $this->assertStringContainsString('Superseded By: DPC-2026-HE-0002', $html);
        $this->assertStringContainsString(
            $this->referenceOf($original)->supersession_effective_at->format('d/m/Y'),
            $html,
        );
    }

    public function test_the_effective_timestamp_is_read_from_metadata_not_the_clock(): void
    {
        // Directive 2's point: the timestamp is a stored fact about when the
        // correction took effect, so what a reader is served does not drift
        // with the day they read it.
        $this->concludeOriginal();
        $original = $this->documentOf($this->proceeding->fresh());

        $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $effective = $this->referenceOf($original)->supersession_effective_at;

        Carbon::setTestNow(Carbon::parse('2031-01-01 09:00'));

        try {
            $this->assertStringContainsString(
                $effective->format('d/m/Y H:i'),
                $this->documents()->contents($original->fresh()),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_document_issued_after_supersession_is_born_superseded(): void
    {
        // Re-issuing the original's promotion list after the correction has
        // taken effect must not produce a document that looks operative.
        $this->concludeOriginal();
        $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $this->documents()->generate($this->proceeding->fresh(), $this->memberOne);

        $reissued = $this->documentOf($this->proceeding->fresh());

        $this->assertSame(2, $reissued->document_version);
        $this->assertTrue($this->referenceOf($reissued)->isSuperseded());
        $this->assertStringContainsString(
            'Superseded By: DPC-2026-HE-0002',
            $this->documents()->contents($reissued),
        );
    }

    public function test_a_review_of_a_review_is_named_as_the_superseding_dpc(): void
    {
        // The case where reading the code off the document's own reference row
        // would be wrong: Review 1's document must name Review 2, not itself.
        $this->concludeOriginal();

        $firstReview = $this->raise($this->proposal);
        $firstProceeding = $this->conclude($firstReview, 'DPC-2026-HE-0002', '2026-09-10');
        $this->documents()->generate($firstProceeding->fresh(), $this->memberOne);
        $firstDocument = $this->documentOf($firstProceeding);

        $this->conclude($this->raise($firstReview, '2026-10-05'), 'DPC-2026-HE-0003', '2026-10-20');

        $html = $this->documents()->contents($firstDocument->fresh());

        $this->assertStringContainsString('Superseded By: DPC-2026-HE-0003', $html);
        $this->assertStringNotContainsString('Superseded By: DPC-2026-HE-0002', $html);
        // And its own identity is unchanged — it still corrects the original.
        $this->assertSame(
            $this->proposal->fresh()->uuid,
            $this->referenceOf($firstDocument)->original_dpc_uuid,
        );
    }

    // ---------------------------------------------------------- permanence

    public function test_a_reference_cannot_be_rewritten(): void
    {
        $this->concludeOriginal();
        $proceeding = $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $reference = $this->referenceOf($this->documentOf($proceeding));

        $this->expectException(RuntimeException::class);
        $reference->update(['chain_uuid' => (string) Str::uuid()]);
    }

    public function test_a_reference_cannot_be_deleted(): void
    {
        $this->concludeOriginal();
        $proceeding = $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');
        $this->documents()->generate($proceeding->fresh(), $this->memberOne);

        $reference = $this->referenceOf($this->documentOf($proceeding));

        $this->expectException(RuntimeException::class);
        $reference->delete();
    }

    public function test_a_recorded_supersession_timestamp_cannot_be_moved(): void
    {
        // A supersession that could be re-dated is a supersession that can be
        // backdated, which is the whole reason for recording it.
        $this->concludeOriginal();
        $original = $this->documentOf($this->proceeding->fresh());

        $this->conclude($this->raise($this->proposal), 'DPC-2026-HE-0002', '2026-09-10');

        $reference = $this->referenceOf($original);

        $this->expectException(RuntimeException::class);
        $reference->update(['supersession_effective_at' => now()->addYear()]);
    }
}
