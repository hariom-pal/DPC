<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Enums;

/**
 * Lifecycle of a single allotment record (PPT S28's STATUS column) — M6
 * Phase 1.
 *
 * Each phase declares the states it can actually reach — the pattern used for
 * `RemarkCode` across M5. Phase 1 brought `pending` and `superseded`; Phase 2
 * brings `accepted` and `scheduled`; the change register of 2026-08-26 brings
 * `returned`.
 *
 * Note this is the status of an *allotment*, not of the proposal. A proposal
 * may have several allotments over time; exactly one is current.
 */
enum AllotmentStatus: string
{
    /** Allotted and awaiting the allottee's action. */
    case Pending = 'pending';

    /** The allottee has accepted the DPC (S30). */
    case Accepted = 'accepted';

    /** A date and time have been set (S30). */
    case Scheduled = 'scheduled';

    /** Replaced by a later allotment. Retained; never deleted or edited. */
    case Superseded = 'superseded';

    /**
     * The completion date passed with the DPC unconcluded, so it went back to the
     * Chairman (change register of 2026-08-26, item 9 as clarified).
     *
     * **Distinct from `Superseded`, and the distinction is the point.** A
     * superseded allotment was moved on from by a decision — the Chairman
     * re-allotted it. A returned one lapsed: nobody decided anything, a date
     * passed. Collapsing them would make "how often do allotments run over" a
     * question the data could not answer, and that is exactly the question a
     * completion deadline exists to raise.
     */
    case Returned = 'returned';

    /**
     * The allottee refused the DPC, with a reason, and it went back to the CGPSC
     * Chairman (business instruction, 2026-09-06).
     *
     * > *The DPC is allotted; there is an option to accept but none to reject. The
     * > allottee should be able to reject it, and it should go back to the Chairman
     * > with the reason, so the Chairman can allot it to somebody else.*
     *
     * **A fourth ending, distinct from the three above, and the distinction is the
     * point.** `Superseded` is the Chairman moving on from a decision they made;
     * `Returned` is a deadline passing with nobody deciding anything; this is the
     * allottee declining — a decision, made by a different person, for a reason
     * that is on the record. Collapsing any two of them would lose the answer to
     * "why did this DPC change hands", which is precisely what a re-allotment has
     * to be able to answer.
     *
     * Architect Decision 3 recorded that there was no member-decline transition
     * and that the Chairman's re-allotment was the only remedy. Business has
     * replaced that decision.
     */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Scheduled => 'Scheduled',
            self::Superseded => 'Superseded',
            self::Returned => 'Returned — not concluded by the due date',
            self::Declined => 'Declined by the allottee',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-warning',
            self::Accepted => 'text-bg-info',
            self::Scheduled => 'text-bg-success',
            self::Superseded => 'text-bg-secondary',
            self::Returned => 'text-bg-danger',
            self::Declined => 'text-bg-danger',
        };
    }

    /**
     * Whether this allotment is the proposal's live one.
     */
    public function isCurrent(): bool
    {
        return $this !== self::Superseded
            && $this !== self::Returned
            && $this !== self::Declined;
    }

    /**
     * The CGPSC workflow state this allotment status represents. The state is
     * always derived, never stored on the proposal (architect Decision 2).
     */
    public function workflowState(): string
    {
        return match ($this) {
            self::Pending => 'allotted',
            self::Accepted => 'accepted',
            self::Scheduled => 'scheduled',
            self::Superseded => 'unallotted',

            // A returned DPC is back with the Chairman and awaiting re-allotment,
            // which is the same workflow state as one never allotted. The *row*
            // remembers that it lapsed; the workflow only needs to know it is the
            // Chairman's again.
            self::Returned => 'unallotted',

            // A declined DPC is the Chairman's again, exactly as a returned one is.
            // The *row* remembers who refused it and why; the workflow only needs to
            // know it is waiting to be allotted.
            self::Declined => 'unallotted',
        };
    }
}
