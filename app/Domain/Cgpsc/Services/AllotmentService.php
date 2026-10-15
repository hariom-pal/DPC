<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allotting a forwarded DPC to the Commission member who will conduct it
 * (PPT S28; change register of 2026-08-26, item 9 as clarified).
 *
 * ### Who allots, and what the clarification of 2026-09-05 settled
 *
 * > *Every approved DPC is automatically routed to the default CGPSC Chairman. The
 * > CGPSC Chairman decides: which member will receive the DPC for execution. Who
 * > will act as the DPC Chairman. The Completion Deadline.*
 *
 * **Three decisions, one act, one actor.** {@see allot()} takes all three and
 * {@see transition()} writes them in a single transaction — the allotment row, and
 * the designation of who presides through `DpcCommitteeService::designateChairman()`.
 *
 * The earlier register of 2026-08-26 read the other way round (*"DPC will be
 * allocated by the DPC Chairman, not by the CGPSC Chairman"*), and this class
 * enforced that: only the designated DPC Chairman could allot. The clarification
 * replaces it, because the earlier reading left the CGPSC Chairman unable to act on
 * a DPC once somebody else was designated — including to perform the very remedies
 * the clarification gives them. {@see assertMayAllot()} now admits the CGPSC
 * Chairman always, and still admits this DPC's designated Chairman.
 *
 * ### Three distinct responsibilities, and two of them may be one person
 *
 * | Role | Who | What they do |
 * |---|---|---|
 * | CGPSC Chairman | holds `Role::Chairman` | allots: names the allottee, the DPC Chairman and the deadline |
 * | DPC Allottee | `dpc_allotments.allotted_to` | coordinates and conducts; schedules the sitting within the deadline |
 * | DPC Chairman | `dpc_committee_members.is_dpc_chairman` | presides over this one sitting |
 *
 * The allottee and the DPC Chairman **may be the same person or different people**,
 * and both are valid — nothing here couples them. The DPC Chairman is valid for
 * this DPC only; no permanent role is granted, which
 * `DpcCommitteeService::designateChairman()` guarantees by writing a column on the
 * membership rather than assigning a role.
 *
 * ### An allotment carries a deadline
 *
 * The register asks for three facts, and the third is what makes the first two
 * useful: when it was allotted, **by when it must be concluded**, and the sitting
 * date the member chooses within that. The member may reschedule, but not past the
 * deadline — that is the Chairman's and not theirs to move.
 *
 * ### An expired allotment goes back
 *
 * > *If the member does not conclude the DPC by the date it was supposed to be
 * > concluded, the DPC returns to the Chairman, and the Chairman allocates it
 * > again.*
 *
 * {@see returnExpired()} does that, and it writes `returned_at` on the lapsed row
 * rather than editing `allotted_to`. Both facts have to stand afterwards — *this
 * member held it until this date and did not conclude it*, and *that member holds
 * it now* — and overwriting the allottee answers only the second. The first is the
 * one an officer will be asked about.
 *
 * **One member may hold several DPCs at once**, which the register says in as many
 * words, so nothing here limits an allottee to a single live allotment.
 *
 * Runs the `dpc_cgpsc` workflow on the proposal as subject, so CGPSC-side state
 * lives in the workflow ledger and this table rather than as new columns on
 * `dpc_proposals` — M4 is architecture-frozen, and duplicating state onto it would
 * create two sources of truth that can disagree.
 *
 * **Nothing is overwritten.** Re-allotting writes a new row and marks the previous
 * one superseded. Every transition records a timeline entry carrying the full
 * domain detail, because workflow history must be reproducible from timeline
 * records alone rather than by reading current state (architect directive).
 */
class AllotmentService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly ActivityTimelineService $timeline,

        /*
         | Designating who presides is part of allotting (business clarification,
         | 2026-09-05), and it is `DpcCommitteeService`'s operation — so it is
         | called rather than reimplemented. Writing `is_dpc_chairman` here would
         | be a second code path for a rule that already has one, and would skip
         | the two checks that make it safe: the member is on *this* committee,
         | and has not been replaced.
         */
        private readonly DpcCommitteeService $committee,
    ) {}

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
        ?Carbon $completeBy = null,
        ?DpcCommitteeMember $dpcChairman = null,
    ): DpcAllotment {
        $this->assertForwardedToCgpsc($proposal);
        $this->assertMayAllot($proposal, $chairman);
        $this->assertAllottable($proposal, $allottee);
        $this->assertDeadlineIsUsable($completeBy, $defaultDpcDate);

        if ($this->current($proposal) !== null) {
            throw new WorkflowException('This DPC is already allotted. Use re-allotment to move it to another member.');
        }

        return $this->transition($proposal, $allottee, $chairman, $request, 'allot', $defaultDpcDate, null, $completeBy, $dpcChairman);
    }

    /**
     * Who may allot this DPC.
     *
     * **The CGPSC Chairman always may** (business clarification, 2026-09-05):
     *
     * > *Every approved DPC is automatically routed to the default CGPSC Chairman.
     * > The CGPSC Chairman decides: which member will receive the DPC for
     * > execution. Who will act as the DPC Chairman. The Completion Deadline.*
     *
     * That settles a question the earlier register left ambiguous. This method used
     * to hand allotment to the **designated DPC Chairman** and let the CGPSC
     * Chairman in only while nobody was designated — which meant that once a
     * Member was designated, the CGPSC Chairman could no longer move their own
     * Commission's DPC, and the clarification's own remedies (reassign the
     * allottee, change the DPC Chairman, extend the deadline) became unreachable
     * by the person it names as holding them.
     *
     * **The designated DPC Chairman may still allot**, and that is kept rather
     * than removed. A DPC Chairman who is running the sitting re-allotting the
     * execution to another Member is a real operation, it was permitted before
     * this clarification, and nothing in the clarification withdraws it. Widening
     * who may act does not require narrowing it elsewhere.
     *
     * Nobody else may. Holding `Role::Member` portal-wide does not make somebody
     * this DPC's Chairman, which is the check the roles in the workflow config
     * cannot express — they gate on "a Commission actor", and this gates on the
     * individual.
     */
    private function assertMayAllot(DpcProposal $proposal, User $actor): void
    {
        if ($actor->hasRole(Role::Chairman->value)) {
            return;
        }

        $designated = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->whereNotNull('is_dpc_chairman')
            ->first();

        if ($designated === null) {
            throw new WorkflowException(
                'This DPC has no DPC Chairman yet. The CGPSC Chairman allots it, naming who will preside.'
            );
        }

        if ($designated->user_id !== $actor->id) {
            throw new WorkflowException(sprintf(
                'Only the CGPSC Chairman or this DPC\'s Chairman may allot it, and that is %s.',
                $designated->name,
            ));
        }
    }

    /**
     * The completion deadline has to be a date the allottee can actually work to.
     *
     * A deadline in the past is refused rather than accepted-and-immediately-
     * overdue: an allotment that is late the moment it is made tells the member
     * nothing and would be swept straight back to the Chairman by
     * {@see returnExpired()}, producing a loop nobody asked for.
     *
     * A sitting date **after** the deadline is refused for the same reason it is
     * refused at scheduling time — the register's sequence is that the member
     * schedules "on or before" the date the DPC must be concluded by.
     */
    private function assertDeadlineIsUsable(?Carbon $completeBy, ?Carbon $defaultDpcDate): void
    {
        if ($completeBy === null) {
            return;
        }

        if ($completeBy->lt(Carbon::today())) {
            throw new WorkflowException('The completion date cannot be in the past.');
        }

        if ($defaultDpcDate !== null && $defaultDpcDate->gt($completeBy)) {
            throw new WorkflowException(
                'The suggested DPC date falls after the date the DPC must be concluded by.'
            );
        }
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
        ?Carbon $completeBy = null,
        ?DpcCommitteeMember $dpcChairman = null,
    ): DpcAllotment {
        $this->assertForwardedToCgpsc($proposal);
        $this->assertMayAllot($proposal, $chairman);
        $this->assertAllottable($proposal, $allottee);
        $this->assertDeadlineIsUsable($completeBy, $defaultDpcDate);

        $existing = $this->current($proposal);

        if ($existing === null) {
            throw new WorkflowException('This DPC has not been allotted yet, so it cannot be re-allotted.');
        }

        /*
         | **Re-allotting to the same member is permitted when something else about
         | the allotment changes** (business clarification, 2026-09-05).
         |
         | The clarification's list of what the CGPSC Chairman may do when a DPC
         | comes back to them opens with *"reassign the same DPC Allottee"*, and
         | goes on to changing the DPC Chairman and extending the deadline. Refusing
         | that outright — which this did — left the Chairman unable to grant an
         | extension to the member already holding the DPC, which is the commonest
         | remedy of the four.
         |
         | What stays refused is a re-allotment that changes *nothing*: same
         | allottee, same deadline, same DPC Chairman. That is not an extension, it
         | is a duplicate row with a reason attached, and it would make the history
         | unreadable.
         */
        if ($existing->allotted_to === $allottee->id && ! $this->changesSomething($existing, $completeBy, $dpcChairman)) {
            throw new WorkflowException(
                'This DPC is already allotted to that member on those terms. '
                .'Change the completion date or the DPC Chairman, or allot it to somebody else.'
            );
        }

        return $this->transition($proposal, $allottee, $chairman, $request, 'reallot', $defaultDpcDate, $reason, $completeBy, $dpcChairman);
    }

    /**
     * Whether a re-allotment to the same member alters anything worth recording.
     *
     * The two things the Chairman may change without moving the DPC: the date it
     * must be concluded by, and who presides. A designation that names the member
     * already designated counts as no change, which is why this compares the
     * committee row rather than merely noticing that one was supplied.
     */
    private function changesSomething(
        DpcAllotment $existing,
        ?Carbon $completeBy,
        ?DpcCommitteeMember $dpcChairman,
    ): bool {
        if ($completeBy?->toDateString() !== $existing->complete_by?->toDateString()) {
            return true;
        }

        if ($dpcChairman === null) {
            return false;
        }

        $designated = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $existing->dpc_proposal_id)
            ->serving()
            ->whereNotNull('is_dpc_chairman')
            ->first();

        return $designated?->id !== $dpcChairman->id;
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
        ?Carbon $completeBy = null,
        ?DpcCommitteeMember $dpcChairman = null,
    ): DpcAllotment {
        $from = $this->state($proposal);
        $def = $this->engine->assertAllowed('dpc_cgpsc', $event, $from, $chairman);

        return DB::transaction(function () use ($proposal, $allottee, $chairman, $request, $event, $def, $from, $defaultDpcDate, $reason, $completeBy, $dpcChairman): DpcAllotment {
            $previous = $this->current($proposal);

            /*
             | **Who presides is decided here, in the same transaction** (business
             | clarification, 2026-09-05).
             |
             | > *The CGPSC Chairman decides: which member will receive the DPC for
             | > execution. Who will act as the DPC Chairman. The Completion
             | > Deadline.*
             |
             | Three decisions, one act. Designating separately afterwards — which
             | is what the committee screen still offers, and what this used to be
             | the only route to — leaves a window in which a DPC is allotted and
             | nobody presides over it, and makes the audit trail carry two events
             | for one decision.
             |
             | Before the allotment row is written, so a designation that is refused
             | (a member of another DPC's committee, or one who has been replaced)
             | takes the whole allotment down with it rather than leaving a DPC
             | allotted under terms the Chairman did not complete.
             */
            $presiding = $dpcChairman === null
                ? null
                : $this->committee->designateChairman($proposal, $dpcChairman, $chairman);

            $allotment = DpcAllotment::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'allotted_to' => $allottee->id,
                'allotted_by' => $chairman->id,
                'allotted_at' => now(),
                'default_dpc_date' => $defaultDpcDate,

                // The date this DPC must be concluded by, set by the DPC Chairman.
                // Nullable for allotments made before the register that introduced
                // it; required by the request on every new one.
                'complete_by' => $completeBy,
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
                    'complete_by' => $completeBy?->toDateString(),
                    'allotted_by_id' => $chairman->id,
                    'allotted_by_name' => $chairman->name,
                    'default_dpc_date' => $defaultDpcDate?->toDateString(),
                    'superseded_allotment_uuid' => $previous?->uuid,
                    'previous_allotted_to_name' => $previous?->allottee->name,

                    /*
                     | Who presides, recorded on the allotment event itself.
                     |
                     | `DpcCommitteeService::designateChairman()` writes its own
                     | timeline entry, so this duplicates a fact — deliberately. The
                     | clarification asks for the reassignment to be *completely
                     | auditable*, and "who was presiding under this allotment" has
                     | to be answerable from the allotment event without joining it
                     | to a designation event by timestamp and hoping.
                     */
                    'dpc_chairman_member_id' => $presiding?->id,
                    'dpc_chairman_name' => $presiding?->name,
                    'dpc_chairman_user_id' => $presiding?->user_id,
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
    private function assertAllottable(DpcProposal $proposal, User $allottee): void
    {
        /*
         | **Membership of *this DPC's* committee is the qualification** (business
         | instruction, 2026-09-06, and the correction of 2026-09-07).
         |
         | This asked for `Role::Member` or `Role::Chairman` until then, which was
         | the old proxy for *"a Commission actor"* — from before an allotment was
         | scoped to a committee at all. Once business ruled that a DPC is allotted
         | to one of the members already named on it, the two rules contradicted
         | each other, and the contradiction had exactly one victim: an ordinary
         | committee member.
         |
         | **A committee member holds no role, and must not.** Item 7 of the register
         | is explicit — *"do NOT assign any permanent Commission Member role to a
         | user"* — because sitting on one DPC's committee is not an office. So the
         | role test refused precisely the people the committee rule elects, and
         | allotting to a member the Chairman had just seated failed with *"A DPC may
         | only be allotted to a CGPSC Member or the Chairman."*
         |
         | Nothing is loosened by dropping it. The committee test is the narrower of
         | the two: a Commission Member not seated on this DPC could be allotted it
         | before and cannot now. `AllotDpcRequest` enforces the same rule against
         | the form; this is the authority behind it, because a service that trusted
         | its request is one route registration away from not being asked.
         */
        $seated = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('user_id', $allottee->id)
            ->exists();

        if (! $seated) {
            throw new WorkflowException(
                'A DPC is allotted to a serving member of its own committee. '
                .'Seat this person on the committee first, or choose somebody already on it.'
            );
        }

        if (! $allottee->is_active) {
            throw new WorkflowException('That account is deactivated and cannot be allotted a DPC.');
        }
    }

    /**
     * Whether this DPC's sitting has concluded.
     *
     * Queried rather than reached through a relation, because `DpcProposal` has
     * none — M4 is architecture-frozen, and adding a relation to it to serve an M6
     * concern is the kind of convenience that turns a frozen model into a mutable
     * one.
     */
    private function hasConcluded(DpcProposal $proposal): bool
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', '!=', DpcProceedingStatus::InProceeding->value)
            ->exists();
    }

    /**
     * Live allotments whose completion date has passed.
     *
     * @return Collection<int, DpcAllotment>
     */
    public function overdue(?Carbon $asOf = null): Collection
    {
        return DpcAllotment::query()
            ->with(['proposal', 'allottee'])
            ->current()
            ->whereNotNull('complete_by')
            ->whereNull('returned_at')
            ->whereDate('complete_by', '<', ($asOf ?? Carbon::today())->toDateString())
            ->orderBy('complete_by')
            ->get();
    }

    /**
     * Send every overdue DPC back to the Chairman for re-allotment (item 9, as
     * clarified).
     *
     * > *If the member does not conclude the DPC by the date it was supposed to be
     * > concluded, the DPC returns to the Chairman, and the Chairman allocates it
     * > again.*
     *
     * **A DPC that has actually been concluded is never returned**, however late.
     * A concluded sitting closes the *proceeding* rather than the allotment, so
     * that is what {@see hasConcluded()} asks. Without the guard, a DPC decided on
     * the due date but recorded a day later would be snatched back from the member
     * who had already done the work.
     *
     * **A sitting that is open but not concluded *is* returned**, and that is the
     * register read literally: the condition it states is "not concluded by the
     * date", not "not started". Whether an open sitting should instead earn a grace
     * period is a business question and is recorded as **BD-31** rather than
     * decided here — inventing a grace period would be inventing a rule.
     *
     * **Marks the row rather than moving the DPC.** Re-allotment is the Chairman's
     * act and needs a new allottee, which nothing here can choose. This records
     * that the allotment lapsed and leaves the proposal unallotted, which is what
     * puts it back on the Chairman's screen.
     *
     * @return Collection<int, DpcAllotment>
     */
    public function returnExpired(?Carbon $asOf = null): Collection
    {
        $returned = new Collection;

        foreach ($this->overdue($asOf) as $allotment) {
            $proposal = $allotment->proposal;

            if ($proposal === null || $this->hasConcluded($proposal)) {
                continue;
            }

            DB::transaction(function () use ($allotment, $proposal, $returned): void {
                $allotment->forceFill([
                    'status' => AllotmentStatus::Returned->value,
                    'returned_at' => now(),
                    'return_reason' => sprintf(
                        'Not concluded by %s, the date set when it was allotted.',
                        $allotment->complete_by?->format('d-m-Y') ?? '—',
                    ),
                ])->save();

                $this->timeline->record(
                    'dpc_cgpsc.allotment_returned',
                    $proposal,
                    sprintf(
                        'Allotment to %s returned to the Chairman — not concluded by %s',
                        $allotment->allottee->name ?? '—',
                        $allotment->complete_by?->format('d-m-Y') ?? '—',
                    ),
                    [
                        'allotment_uuid' => $allotment->uuid,
                        'allotted_to_id' => $allotment->allotted_to,
                        // Named as well as identified, so the history screen reads
                        // the same way for this event as for the two beside it and
                        // needs no join to render a person's name.
                        'allotted_to_name' => $allotment->allottee->name ?? null,
                        'complete_by' => $allotment->complete_by?->toDateString(),
                        'returned_at' => now()->toIso8601String(),
                    ],
                );

                $returned->push($allotment);
            });
        }

        return $returned;
    }

    /**
     * The allottee refuses a DPC, and it goes back to the CGPSC Chairman
     * (business instruction, 2026-09-06).
     *
     * > *The DPC is allotted; there is an option to accept but none to reject. The
     * > allottee should be able to reject it, and it should go back to the Chairman
     * > with the reason, so the Chairman can allot it to somebody else.*
     *
     * **This replaces architect Decision 3**, which recorded that there was no
     * member-decline transition and that the Chairman's re-allotment was the only
     * remedy. That put the Chairman in the position of having to notice, unaided,
     * that a member could not proceed.
     *
     * **Only while it is still pending.** Once the allottee has accepted, the
     * remedy is the Chairman's re-allotment, and after a sitting is scheduled there
     * are participants who have been told a date. Declining is the answer to *"I
     * cannot take this on"*, and that answer belongs before the acceptance rather
     * than after it.
     *
     * **Only by the allottee.** A DPC declined by somebody it was not allotted to
     * is not a refusal, it is an interference — and the reason recorded would name
     * the wrong person.
     *
     * The reason is required by the request and re-checked here: it is the whole
     * value of the transition, because what the Chairman needs in order to allot
     * again is not that somebody refused but why.
     */
    public function decline(DpcProposal $proposal, User $allottee, string $reason, Request $request): DpcAllotment
    {
        $allotment = $this->current($proposal);

        if ($allotment === null) {
            throw new WorkflowException('This DPC is not allotted, so there is nothing to decline.');
        }

        if ($allotment->allotted_to !== $allottee->id) {
            throw new WorkflowException('Only the member a DPC is allotted to may decline it.');
        }

        if ($allotment->status !== AllotmentStatus::Pending) {
            throw new WorkflowException(
                'This DPC has already been accepted. Ask the CGPSC Chairman to re-allot it.'
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new WorkflowException('State why you are declining this DPC — the Chairman allots it again from that.');
        }

        return DB::transaction(function () use ($allotment, $proposal, $allottee, $reason): DpcAllotment {
            /*
             | The same two columns a lapsed allotment writes. They are named for
             | *leaving the allottee*, not for the reason it left, and a decline is
             | the other way that happens — so a second pair of columns would mean
             | every reader of allotment history had to know which pair to look in.
             | The status is what distinguishes them.
             */
            $allotment->forceFill([
                'status' => AllotmentStatus::Declined->value,
                'returned_at' => now(),
                'return_reason' => $reason,
            ])->save();

            $this->timeline->record(
                'dpc_cgpsc.allotment_declined',
                $proposal,
                sprintf('%s declined this DPC — %s', $allottee->name, $reason),
                [
                    'allotment_uuid' => $allotment->uuid,
                    'allotted_to_id' => $allotment->allotted_to,
                    'allotted_to_name' => $allottee->name,
                    'reason' => $reason,
                    'declined_at' => now()->toIso8601String(),
                ],
                $allottee,
            );

            return $allotment;
        });
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
            /*
             | The automatic return is part of this chain, not a separate story
             | (business clarification, 2026-09-05: *"This reassignment must be
             | completely auditable."*).
             |
             | It was written to the timeline from the day the sweep existed and
             | was simply not read back here, so the history showed a DPC moving
             | from one member to another with nothing between them explaining
             | why — which is the one question a reader of this screen has.
             */
            ->whereIn('event', ['dpc_cgpsc.allot', 'dpc_cgpsc.reallot', 'dpc_cgpsc.allotment_returned'])
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
