<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalRevision;
use RuntimeException;

/**
 * Revision management for a DPC proposal (change item 18).
 *
 * **The rule this class exists to hold in one place:** a return for correction
 * bumps the revision number; the DPC Number never changes. Nothing here writes
 * `dpc_code`, and that omission is the whole point — the number is issued once at
 * creation, by `DpcProposalService::createFromPreDpc()`, and is the same string
 * through every round trip.
 *
 * Every method assumes it is already inside the caller's transaction and after the
 * proposal has been locked. That is not laziness: opening a revision is part of
 * the same atomic act as the transition that caused it, and doing it in its own
 * transaction would allow a returned proposal with no revision 2 to exist.
 */
class DpcRevisionService
{
    /**
     * Open revision 1 when the proposal is created.
     */
    public function open(DpcProposal $proposal, User $actor): DpcProposalRevision
    {
        return DpcProposalRevision::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'revision_number' => $proposal->revision_number,
            'status' => $proposal->status->value,
            'remarks' => sprintf(
                'Raised from Pre-DPC %s by %s.',
                $proposal->preDpcProposal === null ? '—' : (string) $proposal->preDpcProposal->pre_dpc_number,
                $actor->name,
            ),
        ]);
    }

    public function recordSubmission(DpcProposal $proposal, User $actor): void
    {
        $this->current($proposal)->forceFill([
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'status' => $proposal->status->value,
        ])->save();
    }

    public function recordApproval(DpcProposal $proposal): void
    {
        // Deliberately only the status. Who approved and when are on the proposal
        // and in the workflow ledger; repeating them here would be a third copy
        // of one fact, and the revision's own subject is the *round trip*, which
        // an approval ends rather than participates in.
        $this->current($proposal)->forceFill(['status' => $proposal->status->value])->save();
    }

    public function recordCancellation(DpcProposal $proposal): void
    {
        $this->current($proposal)->forceFill(['status' => $proposal->status->value])->save();
    }

    /**
     * Close the returned revision and open the next.
     *
     * The proposal's `revision_number` is bumped here rather than by the caller,
     * so the counter and the row that depends on it cannot be written by two
     * pieces of code that disagree about the order.
     */
    public function recordReturn(DpcProposal $proposal, User $actor, string $reason): DpcProposalRevision
    {
        $closing = $this->current($proposal);

        $closing->forceFill([
            'returned_at' => now(),
            'returned_by' => $actor->id,
            'return_reason' => $reason,
            'status' => $proposal->status->value,
        ])->save();

        $next = $closing->revision_number + 1;

        $proposal->forceFill(['revision_number' => $next])->save();

        return DpcProposalRevision::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'revision_number' => $next,
            'status' => $proposal->status->value,
            'remarks' => sprintf('Opened because revision %d was returned for correction.', $closing->revision_number),
        ]);
    }

    /**
     * The revision now in progress.
     *
     * Refuses rather than creating one when a proposal has none. A proposal
     * without a revision predates item 18 and was backfilled with revision 1 by
     * migration; one appearing at runtime would mean a creation path that skipped
     * {@see open()}, and silently minting a revision here would hide it while
     * losing the history the register asked for.
     */
    private function current(DpcProposal $proposal): DpcProposalRevision
    {
        $revision = DpcProposalRevision::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->orderByDesc('revision_number')
            ->first();

        if ($revision === null) {
            throw new RuntimeException(sprintf(
                'DPC proposal %d has no revision to record against. Every proposal opens revision 1 when it is '
                .'created; a proposal without one was created by a path that does not.',
                $proposal->id,
            ));
        }

        return $revision;
    }
}
