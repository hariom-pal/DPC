<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allotment of a forwarded DPC proposal to a Chairman or Member (PPT S28) —
 * M6 Phase 1.
 *
 * Runs the `dpc_cgpsc` workflow on the proposal as subject, so CGPSC-side
 * state lives in the workflow ledger and this table rather than as new columns
 * on `dpc_proposals` — M4 is architecture-frozen, and duplicating state onto
 * it would create two sources of truth that can disagree.
 *
 * **Nothing is overwritten.** Re-allotting writes a new row and marks the
 * previous one superseded. Every transition records a timeline entry carrying
 * the full domain detail, because workflow history must be reproducible from
 * timeline records alone rather than by reading current state (architect
 * directive).
 */
class AllotmentService
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * The proposal's live allotment, or null if it has never been allotted.
     */
    public function current(DpcProposal $proposal): ?DpcAllotment
    {
        return DpcAllotment::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->current()
            ->latest('allotted_at')
            ->first();
    }

    /**
     * The CGPSC-side workflow state, **derived** rather than stored (architect
     * Decision 2): the current allotment's status is the single source of
     * truth, and nothing about it is duplicated onto the frozen proposal.
     */
    public function state(DpcProposal $proposal): string
    {
        $current = $this->current($proposal);

        return $current === null ? 'unallotted' : $current->status->workflowState();
    }

    public function allot(
        DpcProposal $proposal,
        User $allottee,
        User $chairman,
        Request $request,
        ?Carbon $defaultDpcDate = null,
    ): DpcAllotment {
        $this->assertForwardedToCgpsc($proposal);
        $this->assertAllottable($allottee);

        if ($this->current($proposal) !== null) {
            throw new WorkflowException('This DPC is already allotted. Use re-allotment to move it to another member.');
        }

        return $this->transition($proposal, $allottee, $chairman, $request, 'allot', $defaultDpcDate, null);
    }

    /**
     * Move a DPC to a different allottee (S30's RE-ALLOTMENT).
     *
     * The Chairman's remedy when an allottee cannot proceed — there is no
     * member-decline transition (architect Decision 3).
     */
    public function reallot(
        DpcProposal $proposal,
        User $allottee,
        User $chairman,
        Request $request,
        string $reason,
        ?Carbon $defaultDpcDate = null,
    ): DpcAllotment {
        $this->assertForwardedToCgpsc($proposal);
        $this->assertAllottable($allottee);

        $existing = $this->current($proposal);

        if ($existing === null) {
            throw new WorkflowException('This DPC has not been allotted yet, so it cannot be re-allotted.');
        }

        if ($existing->allotted_to === $allottee->id) {
            throw new WorkflowException('This DPC is already allotted to that member.');
        }

        return $this->transition($proposal, $allottee, $chairman, $request, 'reallot', $defaultDpcDate, $reason);
    }

    /**
     * Both paths share one implementation: write the new allotment, supersede
     * the old one if there is one, and record the transition.
     */
    private function transition(
        DpcProposal $proposal,
        User $allottee,
        User $chairman,
        Request $request,
        string $event,
        ?Carbon $defaultDpcDate,
        ?string $reason,
    ): DpcAllotment {
        $from = $this->state($proposal);
        $def = $this->engine->assertAllowed('dpc_cgpsc', $event, $from, $chairman);

        return DB::transaction(function () use ($proposal, $allottee, $chairman, $request, $event, $def, $from, $defaultDpcDate, $reason): DpcAllotment {
            $previous = $this->current($proposal);

            $allotment = DpcAllotment::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'allotted_to' => $allottee->id,
                'allotted_by' => $chairman->id,
                'allotted_at' => now(),
                'default_dpc_date' => $defaultDpcDate,
                'status' => AllotmentStatus::Pending->value,
                'reallotment_reason' => $reason,
            ]);

            if ($previous !== null) {
                // Superseded, never edited or deleted — the chain is the history.
                $previous->update([
                    'status' => AllotmentStatus::Superseded->value,
                    'superseded_by_id' => $allotment->id,
                ]);
            }

            $this->engine->record(
                $proposal,
                'dpc_cgpsc',
                $event,
                $from,
                $def,
                $chairman,
                $request,
                $reason,
                null,
                $proposal->department_id,
                // Everything a reader needs to reconstruct this event without
                // reading the allotment table (architect directive).
                [
                    'allotment_uuid' => $allotment->uuid,
                    'allotted_to_id' => $allottee->id,
                    'allotted_to_name' => $allottee->name,
                    'allotted_by_id' => $chairman->id,
                    'allotted_by_name' => $chairman->name,
                    'default_dpc_date' => $defaultDpcDate?->toDateString(),
                    'superseded_allotment_uuid' => $previous?->uuid,
                    'previous_allotted_to_name' => $previous?->allottee->name,
                ],
                // The allottee is a CGPSC user with no department, so no
                // role-plus-department query can reach them (Decision 5).
                $this->notifyUserIds($allottee, $previous),
            );

            return $allotment;
        });
    }

    /**
     * @return list<int>
     */
    private function notifyUserIds(User $allottee, ?DpcAllotment $previous): array
    {
        $ids = [$allottee->id];

        // On re-allotment the outgoing member is told too — they may already
        // have been preparing for a DPC that is no longer theirs.
        if ($previous !== null && $previous->allotted_to !== $allottee->id) {
            $ids[] = $previous->allotted_to;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Only a proposal the department has approved and forwarded is CGPSC's to
     * allot (S19 → S26).
     */
    private function assertForwardedToCgpsc(DpcProposal $proposal): void
    {
        if ($proposal->status !== DpcProposalStatus::Approved || $proposal->forwarded_at === null) {
            throw new WorkflowException(
                'This proposal has not been approved and forwarded to CGPSC, so it cannot be allotted.'
            );
        }
    }

    /**
     * S28 allots to a Member, or to the Chairman himself. Members are resolved
     * dynamically from the role — the PPT's M1–M5 columns are a layout, not a
     * limit of five (architect Decision 1).
     */
    private function assertAllottable(User $allottee): void
    {
        if (! $allottee->hasAnyRole([Role::Member->value, Role::Chairman->value])) {
            throw new WorkflowException('A DPC may only be allotted to a CGPSC Member or the Chairman.');
        }

        if (! $allottee->is_active) {
            throw new WorkflowException('That account is deactivated and cannot be allotted a DPC.');
        }
    }

    /**
     * Allotment history for a proposal, read **only** from timeline records.
     *
     * The architect directive is that workflow history must always be
     * reproducible entirely from the timeline and never reconstructed from
     * current state. This method is that guarantee made concrete: it touches
     * no allotment row, so if the timeline could not answer the question, this
     * would visibly fail rather than quietly fall back.
     *
     * @return Collection<int, ActivityTimeline>
     */
    public function historyFromTimeline(DpcProposal $proposal)
    {
        return ActivityTimeline::query()
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->whereIn('event', ['dpc_cgpsc.allot', 'dpc_cgpsc.reallot'])
            ->with('causer')
            // Occurred-at then UUID (architect directive). `occurred_at` has
            // second granularity, so two events written in one transaction
            // share a timestamp and would otherwise come back in whatever
            // order the database chose — a history that reorders itself
            // between reads is not a history. Never the sequential id.
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();
    }
}
