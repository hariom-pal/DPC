<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Documents;

use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders proceedings documents as self-contained HTML — M7 Phase 4.
 *
 * HTML now, PDF later (architect ruling AQ-3), and the decision was to answer
 * AQ-3 and BD-4 together: if the CSC eSign API turns out to require a PDF
 * container, a `PdfDocumentRenderer` implements the same contract and nothing
 * in the approval path changes.
 *
 * The document is rendered **entirely from stored data** — the pinned
 * calculation run, the corrections, the holds. Nothing is recomputed at render
 * time, so a document produced today and the same document re-rendered in
 * three years say the same thing even if the rules have changed since.
 */
class HtmlDocumentRenderer implements DocumentRenderer
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly ResultApprovalService $approvals,
        private readonly ProceedingCorrectionService $corrections,
        private readonly HoldService $holds,
    ) {}

    public function version(): string
    {
        return (string) config('dpc.proceeding.documents.generator_version', 'html-v1');
    }

    public function contentType(): string
    {
        return 'text/html';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function render(DpcProceeding $proceeding, DocumentType $type): string
    {
        $proceeding->loadMissing([
            'proposal.department', 'proposal.promotionFromPost', 'proposal.promotionToPost',
            'conductedBy', 'closedBy', 'schedule',
        ]);

        $run = $proceeding->final_calculation_run_id !== null
            ? $proceeding->finalCalculationRun
            : null;

        return $this->views->make($type->view(), [
            'proceeding' => $proceeding,
            'proposal' => $proceeding->proposal,
            'run' => $run,
            'lists' => $run === null ? null : $this->approvals->lists($run),
            'holds' => $this->holds->activeBySn($proceeding),
            'corrections' => $this->corrections->filter($proceeding, inForceOnly: true),
            'generatorVersion' => $this->version(),
            'generatorCommit' => (string) config('dpc.application.git_commit'),
            'engineVersion' => $proceeding->calculation_engine_version,
        ])->render();
    }
}
