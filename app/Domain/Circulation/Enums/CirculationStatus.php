<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Enums;

/**
 * The states a circulation note passes through (PPT S45, S47) — M9 Phases 1–2.
 *
 * Phase 1 declared the department's five states; **Phase 2 adds `consented`
 * and `signed`**, which is what the earlier note said would happen — they
 * arrive with the transitions that perform them, not before.
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

    /** The Commission has decided every officer. The note awaits signature. */
    case Consented = 'consented';

    /** Signed by the Commission — **the operative act**. Holds are released here. */
    case Signed = 'signed';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted to Administrative Head',
            self::Returned => 'Returned for correction',
            self::Approved => 'Approved — forwarded to the Commission',
            self::Consented => 'Consented by the Commission — awaiting signature',
            self::Signed => 'Signed by the Commission — promotions released',
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
        return $this === self::Cancelled || $this === self::Signed;
    }

    /**
     * The Commission is holding it.
     */
    public function isWithCommission(): bool
    {
        return $this === self::Approved || $this === self::Consented;
    }
}
