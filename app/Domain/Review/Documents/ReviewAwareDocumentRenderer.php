<?php

declare(strict_types=1);

namespace App\Domain\Review\Documents;

use App\Domain\Proceeding\Documents\HtmlDocumentRenderer;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Services\ReviewDpcService;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * A Review's documents state what they correct (architect ruling AQ-13) —
 * M8 Phase 3.
 *
 * Five facts, on every document a Review produces: the **original DPC**, the
 * **original date**, the **review DPC**, the **review date**, and the
 * **reasons**. A promotion list dated 2027 that silently corrects one from 2017
 * is misleading on its face — and the reader most likely to be misled is the
 * one reading it years later, with nobody left to ask.
 *
 * A decorator over M7's renderer (AD-20): the review block is prepended, and a
 * Fresh DPC's document is returned exactly as M7 rendered it.
 */
class ReviewAwareDocumentRenderer extends HtmlDocumentRenderer
{
    public function __construct(
        ViewFactory $views,
        ResultApprovalService $approvals,
        ProceedingCorrectionService $corrections,
        HoldService $holds,
        private readonly ReviewDpcService $reviews,
        private readonly ViewFactory $viewFactory,
    ) {
        parent::__construct($views, $approvals, $corrections, $holds);
    }

    public function render(DpcProceeding $proceeding, DocumentType $type): string
    {
        $html = parent::render($proceeding, $type);

        $link = $this->reviews->linkForReview($proceeding->proposal);

        if ($link === null) {
            return $html;
        }

        return $this->viewFactory->make('documents.review-context', [
            'link' => $link,
            'original' => $link->original,
            'review' => $proceeding->proposal,
            'reasons' => $this->reviews->reasons($proceeding->proposal),
            'documentUuid' => $this->documentBeingRendered($proceeding, $type),
        ])->render().$html;
    }

    /**
     * The UUID of the document this render will become (architect Directive 3:
     * every document must reference itself).
     *
     * The renderer is handed a **proceeding**, not a document — M7's contract,
     * and it is frozen. But M7 creates the document row *before* it renders
     * into it, deliberately, so that a generation which never completes leaves
     * evidence of an expected-but-absent legal document. That row is therefore
     * already there, and it is the highest version of its type because
     * `nextVersion()` is `max + 1`.
     *
     * So the UUID is read rather than passed. Null only if this is a re-render
     * outside generation, where the printed copy falls back to the reference
     * record — which carries it regardless.
     */
    private function documentBeingRendered(DpcProceeding $proceeding, DocumentType $type): ?string
    {
        return DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('type', $type->value)
            ->orderByDesc('document_version')
            ->value('uuid');
    }
}
