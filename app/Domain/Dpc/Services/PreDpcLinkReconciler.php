<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record which Pre-DPC sanctioned a DPC raised before change item 11.
 *
 * **Why the link is missing, and why a migration was not allowed to invent it.**
 * Since item 11 a DPC exists only under an approved Pre-DPC, and
 * `DpcProposalService::createFromPreDpc()` is the only way to make one. Proposals
 * that predate that rule named their posts directly, and the Pre-DPCs of the time
 * named a *list* of posts — so for a given old proposal there is often more than
 * one order that could have sanctioned it. Migration `360007` therefore left
 * `pre_dpc_proposal_id` **null** rather than guessing: a guessed link looks
 * authoritative, is unfalsifiable, and would be printed on a document.
 *
 * **So this asks a person, and only offers what is arguable.** A candidate order
 * is one for the same department, the same post pair and — where the proposal
 * records one — the same DPC Year. Anything else is not offered at all, because an
 * Administrator presented with a long list of near-misses will pick one.
 *
 * Nothing here is automatic, and nothing is overwritten: a proposal that already
 * carries a link is not a candidate for this screen.
 */
class PreDpcLinkReconciler
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * Proposals with no sanctioning order recorded.
     *
     * @return Collection<int, DpcProposal>
     */
    public function unlinked(): Collection
    {
        return DpcProposal::query()
            ->whereNull('pre_dpc_proposal_id')
            ->with(['department', 'promotionFromPost', 'promotionToPost'])
            ->orderBy('department_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The orders that could have sanctioned this proposal.
     *
     * Deliberately narrow. The DPC Year is only applied when the proposal records
     * one — an old proposal may not — because filtering on a year the proposal does
     * not have would return nothing and read as "no candidate exists".
     *
     * @return Collection<int, PreDpcProposal>
     */
    public function candidatesFor(DpcProposal $proposal): Collection
    {
        return PreDpcProposal::query()
            ->where('department_id', $proposal->department_id)
            // The order must sanction promotion from the post the proposal names —
            // an order may name several, so this is a `whereHas` and not a column
            // comparison.
            ->whereHas(
                'promotionSources',
                fn ($query) => $query->where('promotion_from_post_id', $proposal->promotion_from_post_id),
            )
            ->where('promotion_to_post_id', $proposal->promotion_to_post_id)
            ->when(
                $proposal->dpc_year !== null,
                fn ($query) => $query->where('dpc_year', $proposal->dpc_year),
            )
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Record the order the Administrator identified.
     *
     * Refuses rather than corrects in three cases, each of which would otherwise
     * write a link that cannot be true: a proposal that already has one, an order
     * belonging to a different department, and an order for a different post pair.
     * The check is here and not only in the request because this is the method a
     * future importer would call.
     */
    public function link(DpcProposal $proposal, PreDpcProposal $preDpc, string $actorName): void
    {
        if ($proposal->pre_dpc_proposal_id !== null) {
            throw new RuntimeException(sprintf(
                'DPC %s already records a sanctioning order. A recorded link is never overwritten — '
                .'a correction is a Review DPC, not an edit.',
                $proposal->dpc_code ?? $proposal->uuid,
            ));
        }

        $sanctionsTheFeeder = $preDpc->promotionSources
            ->contains('promotion_from_post_id', $proposal->promotion_from_post_id);

        if ($preDpc->department_id !== $proposal->department_id
            || $preDpc->promotion_to_post_id !== $proposal->promotion_to_post_id
            || ! $sanctionsTheFeeder) {
            throw new RuntimeException(
                'That Pre-DPC sanctions a different exercise. Only an order for the same department and the same '
                .'Promotion To Post, naming the proposal\'s Promotion From Post among its sources, can have '
                .'sanctioned this proposal.'
            );
        }

        DB::transaction(function () use ($proposal, $preDpc, $actorName): void {
            $proposal->forceFill(['pre_dpc_proposal_id' => $preDpc->id])->save();

            // Recorded as a statement about *who decided*, because that is the only
            // evidence there is: the link was identified by a person, not derived.
            $this->timeline->record(
                'dpc_proposal.pre_dpc_recorded',
                $proposal,
                sprintf(
                    'Sanctioning order recorded as %s by %s. Identified by hand — this proposal predates the '
                    .'rule that a DPC is raised from a Pre-DPC.',
                    $preDpc->pre_dpc_number ?? $preDpc->uuid,
                    $actorName,
                ),
            );
        });
    }
}
