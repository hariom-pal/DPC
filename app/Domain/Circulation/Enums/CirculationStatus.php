<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Enums;

/**
 * The states a circulation note passes through (PPT S45, S47) — M9 Phase 1.
 *
 * **Phase 1 declares only the states it can reach.** `consented` and `signed`
 * belong to the Commission's side and arrive with Phase 2, which performs
 * them. The standing project rule — a transition nothing can perform reads as
 * supported behaviour when it is not — applies to a state as much as to a
 * transition.
 *
 * `signed` is where a hold is released (AQ-17), not `consented` and not
 * `approved`: a note that is consented but never signed must leave the
 * promotion reserved, for the same reason a Review that is never approved
 * leaves the original DPC standing (AQ-9).
 */
enum CirculationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted to Administrative Head',
            self::Returned => 'Returned for correction',
            self::Approved => 'Approved — forwarded to the Commission',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The department may still edit its own note.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
