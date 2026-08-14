<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Enums;

/**
 * Lifecycle of a DPC proceeding (PPT S31–S34) — M7 Phase 1.
 *
 * Each phase declares the states it can actually reach — the pattern used for
 * `RemarkCode` and `AllotmentStatus`. Phase 1 produces only `InProceeding`;
 * `ResultApproved` and `Cancelled` arrive with Phase 3, which implements the
 * transitions that reach them. A state nothing can reach reads as supported
 * behaviour when it is not.
 *
 * `isTerminal()` is declared now and already correct for all three, so the
 * phase that starts producing the terminal states inherits the immutability
 * guarantee rather than having to add it.
 */
enum DpcProceedingStatus: string
{
    /** The sitting is open; the Commission may correct and calculate. */
    case InProceeding = 'in_proceeding';

    /** APPROVE BY CGPSC has been given (S34). Phase 3. */
    case ResultApproved = 'result_approved';

    /** CANCEL DPC (S33). Phase 3. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProceeding => 'In Proceeding',
            self::ResultApproved => 'Result Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::InProceeding => 'text-bg-primary',
            self::ResultApproved => 'text-bg-success',
            self::Cancelled => 'text-bg-dark',
        };
    }

    /**
     * A terminal proceeding is frozen — no role, including an administrator,
     * may alter it. Correction after conclusion is a Review DPC (M8), which
     * creates a new proposal rather than editing this record.
     */
    public function isTerminal(): bool
    {
        return $this !== self::InProceeding;
    }

    /**
     * The workflow state this status represents. Derived, never stored on the
     * frozen proposal — two sources of truth can disagree.
     */
    public function workflowState(): string
    {
        return match ($this) {
            self::InProceeding => 'in_proceeding',
            self::ResultApproved => 'result_approved',
            self::Cancelled => 'cancelled',
        };
    }
}
