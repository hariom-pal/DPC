<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Review\Services\ReviewDpcService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Makes "THIS IS A REVIEW DPC" available to the layout (architect Directive 3,
 * M8 Phase 1).
 *
 * A composer rather than an edit to each screen, for one reason: **the M4
 * proposal views are frozen.** A Review traverses M4's own screens unchanged,
 * so the banner has to reach them from outside — the layout renders it when the
 * route's bound proposal turns out to be a Review.
 *
 * Costs at most one query, and only on a route that actually binds a proposal.
 */
class ReviewDpcComposer
{
    public function __construct(
        private readonly ReviewDpcService $reviews,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $proposal = $this->request->route('dpcProposal');

        if (! $proposal instanceof DpcProposal) {
            $view->with('isReviewDpc', false);

            return;
        }

        $link = $this->reviews->linkForReview($proposal);

        if ($link === null) {
            $view->with('isReviewDpc', false);

            return;
        }

        $original = $link->original;

        $view->with('isReviewDpc', true);
        $view->with('reviewChainContext', [
            'proposal' => $proposal,
            'review_number' => $link->review_number,
            'chain_uuid' => $link->chain_uuid,
            'original_dpc_code' => $original?->dpc_code,
            'original_proposal_date' => $original?->proposal_date?->format('d/m/Y'),
            'reasons' => $this->reviews->reasons($proposal)
                ->map(fn ($r): string => (string) ($r->reason->name_en ?: $r->reason->code))
                ->filter()
                ->values()
                ->all(),
        ]);
    }
}
