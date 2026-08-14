<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Enums;

/**
 * Why a promotion is held (`CIR`) — PPT S45, Conventional deck C3 — M7 Phase 4.
 *
 * A hold is a **procedural reservation of the promotion action, not a verdict
 * on merit**. S33's US8 scores 20, qualifies for a Super Seat, and is held
 * anyway on a missing 2016 immovable-property return. That independence is why
 * holds sit outside the frozen scoring engine (architect ruling AQ-4).
 *
 * M7 marks a hold; **M9 resolves it**.
 */
enum HoldReason: string
{
    /**
     * C3: "CR unavailable for two or more years". The S14 label states the
     * same rule from the other end as "CR ≤ 4" — with six CR columns on the
     * chart those are the same condition, which is why ambiguity Q12 resolves
     * rather than needing a ruling.
     */
    case CrYearsMissing = 'cr_years_missing';

    /** C3: "immovable-property details not recorded" for one or more years. */
    case PropertyMissing = 'property_missing';

    /** Recorded by the Commission at the sitting for a reason of its own. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::CrYearsMissing => 'CR not available for the required years',
            self::PropertyMissing => 'Immovable property return not recorded',
            self::Manual => 'Held by the Commission',
        };
    }

    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }
}
