<?php

declare(strict_types=1);

namespace App\Domain\Review\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Documents\DocumentRenderer;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Review\Models\DpcDocumentReviewReference;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;

/**
 * A superseded DPC's documents stay downloadable, watermarked `SUPERSEDED`
 * (architect Directive 5, M8 Phase 1) — M8 Phase 3.
 *
 * **The watermark is applied when the document is served, never when it is
 * stored.** A document is generated once and is immutable (M7's rule, AD-4):
 * rewriting the stored bytes to add a watermark would change an artefact
 * already issued, and would break the SHA-256 that proves a department's copy
 * is the copy the Commission gave it.
 *
 * So the file on disk stays exactly as signed, and the watermark is presented
 * on delivery. That is also the honest model of what happened: the document was
 * issued as it stands, and was *later* superseded. Baking the watermark in
 * would assert it had been superseded when it was issued.
 *
 * `contents()` is therefore **what a reader is served**; `storedContents()` and
 * `verify()` are **what was issued**. Keeping the two apart is the point of
 * this class.
 */
class ReviewAwareDocumentService extends ProceedingDocumentService
{
    public function __construct(
        DocumentRenderer $renderer,
        SignatureService $signatures,
        ActivityTimelineService $timeline,
        private readonly ReviewDpcService $reviews,
        private readonly ViewFactory $views,
    ) {
        parent::__construct($renderer, $signatures, $timeline);
    }

    /**
     * Generate, then record the four permanent references every document must
     * carry (architect Directive 3): the original DPC, the review DPC, the
     * chain, and the document itself.
     *
     * Two DPCs in a chain produce documents: the **Review**, whose documents
     * are stamped here, and an **already-superseded original** whose documents
     * are re-issued afterwards — those are stamped too, and with the
     * supersession already in effect, so a document issued after the correction
     * says so on its face rather than only in its record.
     *
     * A Fresh DPC in no chain gets nothing. Its documents acquire a reference
     * later, if and when a Review supersedes it.
     *
     * @return EloquentCollection<int, DpcProceedingDocument>
     */
    public function generate(DpcProceeding $proceeding, User $actor): EloquentCollection
    {
        $documents = parent::generate($proceeding, $actor);

        $link = $this->reviews->linkForReview($proceeding->proposal);
        $superseded = false;

        if ($link === null) {
            $link = $this->reviews->supersedingLinkFor($proceeding->proposal);
            $superseded = $link !== null;
        }

        if ($link === null) {
            return $documents;
        }

        foreach ($documents as $document) {
            $this->recordReference($document, $link, superseded: $superseded);
        }

        return $documents;
    }

    /**
     * Attach the chain's identifiers to a document, and — when supersession has
     * taken effect — the timestamp at which it did (Directives 2 and 3).
     *
     * Idempotent: re-issuing a document creates a new row for the new version,
     * and an existing reference is never rewritten except to record the
     * supersession timestamp once.
     */
    public function recordReference(
        DpcProceedingDocument $document,
        DpcReviewLink $link,
        bool $superseded = false,
    ): DpcDocumentReviewReference {
        $existing = DpcDocumentReviewReference::query()
            ->where('dpc_proceeding_document_id', $document->id)
            ->first();

        if ($existing !== null) {
            if ($superseded && ! $existing->isSuperseded()) {
                $existing->update(['supersession_effective_at' => $link->superseded_at ?? now()]);
            }

            return $existing->fresh();
        }

        return DpcDocumentReviewReference::query()->create([
            'dpc_proceeding_document_id' => $document->id,
            'document_uuid' => $document->uuid,
            'original_dpc_uuid' => (string) $link->original?->uuid,
            'review_dpc_uuid' => (string) $link->review?->uuid,
            'chain_uuid' => $link->chain_uuid,
            'review_number' => $link->review_number,
            'review_dpc_code' => $link->review?->dpc_code,
            'supersession_effective_at' => $superseded ? ($link->superseded_at ?? now()) : null,
        ]);
    }

    /**
     * What a reader is served — watermarked if this DPC has been superseded.
     */
    public function contents(DpcProceedingDocument $document): string
    {
        $stored = $this->storedContents($document);

        $link = $this->reviews->supersedingLinkFor($document->proceeding?->proposal);

        if ($link === null) {
            return $stored;
        }

        return $this->views->make('documents.superseded-watermark', [
            'link' => $link,
            'review' => $link->review,
            'reference' => DpcDocumentReviewReference::query()
                ->where('dpc_proceeding_document_id', $document->id)
                ->first(),
            'document' => $document,
        ])->render().$stored;
    }

    /**
     * The bytes exactly as issued and signed. Never watermarked.
     */
    public function storedContents(DpcProceedingDocument $document): string
    {
        if (! $document->isAvailable()) {
            throw new WorkflowException('That document has not been generated.');
        }

        return (string) Storage::disk((string) $document->disk)->get((string) $document->path);
    }

    /**
     * Integrity is checked against **what was issued**, not against what is
     * served. A watermark added on delivery must not make a valid document
     * look tampered with.
     */
    public function verify(DpcProceedingDocument $document): bool
    {
        return hash('sha256', $this->storedContents($document)) === $document->content_hash;
    }
}
