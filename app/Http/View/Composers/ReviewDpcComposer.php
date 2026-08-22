<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Review\Services\ReviewDpcService;
use App\Enums\Role;
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
            'can_view_chain' => $this->canViewChain($proposal),
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

    /**
     * Whether the viewer can actually open the Review Chain screen.
     *
     * **This exists because the banner was offering a link that 403'd.** The
     * composer fires on any route that binds a proposal, and the Commission side
     * has four of those — `/cgpsc/allotments/{dpcProposal}/history` and the
     * schedule equivalents. So a Chairman or Member conducting a Review DPC saw
     * the banner, pressed **Review Chain**, and got an access-denied page. Found
     * by crawling the application as each role in a browser; no test saw it,
     * because every test that renders the banner does so as a departmental user,
     * and a link is not a request.
     *
     * The chain screen is departmental: `dpc-proposals/*` is gated to
     * `authorized_officer|administrative_head` by middleware, and `view` on the
     * policy additionally requires the same department. Both are checked here, in
     * that order, so this cannot drift from the route in only one respect.
     *
     * The banner still tells the Commission everything the deck requires — which
     * DPC is being corrected, its date, the review number and the reasons. Only
     * the department-side chain-and-timeline screen is withheld, because opening
     * it to the Commission would be a new screen rather than a fix.
     */
    private function canViewChain(DpcProposal $proposal): bool
    {
        $user = $this->request->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value])
            && $user->can('view', $proposal);
    }
}
