<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Documents;

use App\Domain\Circulation\Enums\CirculationDocumentType;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Services\CirculationResultService;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders circulation documents as self-contained HTML — M9 Phase 3.
 *
 * **Rendered entirely from stored data.** The origin's approved result is read
 * through `CirculationResultService`, which reads `DpcCalculationRun` and
 * `DpcCalculationResult` as models from the run the proceeding pinned at
 * approval. **Nothing is recomputed at render time (AD-23)**, so a document
 * produced today and the same document re-rendered in three years say the same
 * thing even if the rules have changed since.
 *
 * **§23.1 — no effective date is invented.** The note states two facts and
 * asserts neither as governing: the origin DPC's `promotion_as_on_year`,
 * captured at approval and never derived (AQ-7), and the date the reservation
 * was released. Which of them governs seniority and pay is **BD-14**, and it
 * is a service-law consequence the Commission states, not a field this
 * renderer computes.
 */
class HtmlCirculationDocumentRenderer implements CirculationDocumentRenderer
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly CirculationResultService $results,
    ) {}

    public function version(): string
    {
        return (string) config('dpc.circulation.documents.generator_version', 'circ-html-v1');
    }

    public function contentType(): string
    {
        return 'text/html';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function render(DpcCirculation $circulation, CirculationDocumentType $type): string
    {
        $circulation->loadMissing([
            'items.hold', 'department', 'originProposal', 'originProceeding', 'consentedBy', 'approvedBy',
        ]);

        return $this->views->make($type->view(), [
            'circulation' => $circulation,
            'items' => $circulation->items->sortBy('sn'),
            'rows' => $this->results->beforeAndAfter($circulation),
            'run' => $this->results->approvedRun($circulation),
            // Stated, never asserted as governing — §23.1.
            'promotionAsOnYear' => $circulation->originProceeding?->promotion_as_on_year,
            'effectiveDateBasis' => config('dpc.circulation.effective_date_basis'),
            'generatorVersion' => $this->version(),
            'generatorCommit' => (string) config('dpc.application.git_commit'),
            'renderedAt' => now(),
        ])->render();
    }
}
