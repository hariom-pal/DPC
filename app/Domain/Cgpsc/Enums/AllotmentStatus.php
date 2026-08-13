<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Enums;

/**
 * Lifecycle of a single allotment record (PPT S28's STATUS column) — M6
 * Phase 1.
 *
 * Each phase declares the states it can actually reach — the pattern used for
 * `RemarkCode` across M5. Phase 1 brought `pending` and `superseded`; Phase 2
 * brings `accepted` and `scheduled`.
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

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Scheduled => 'Scheduled',
            self::Superseded => 'Superseded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-warning',
            self::Accepted => 'text-bg-info',
            self::Scheduled => 'text-bg-success',
            self::Superseded => 'text-bg-secondary',
        };
    }

    /**
     * Whether this allotment is the proposal's live one.
     */
    public function isCurrent(): bool
    {
        return $this !== self::Superseded;
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
        };
    }
}
