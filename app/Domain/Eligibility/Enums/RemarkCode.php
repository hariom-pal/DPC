<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Enums;

/**
 * Remark codes the engine can produce (requirements §6.3) — M5 Phase 1.
 *
 * Only codes the engine can actually produce are declared, and each phase adds
 * its own: `EL`/`NE` in Phase 1, `SS` (Super Seat) here in Phase 2. `CIR`
 * (circulation, promotion held) arrives with M9 — declaring it now would put a
 * value in the enum that nothing can ever reach, which reads as supported
 * behaviour when it is not.
 *
 * `CC` / `PUNISHMENT` are not here at all — those are Note values the officer
 * types on the S18 chart, not verdicts the engine reaches.
 */
enum RemarkCode: string
{
    case Eligible = 'EL';
    case NotEligible = 'NE';
    case SuperSeat = 'SS';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::NotEligible => 'Not Eligible',
            self::SuperSeat => 'Super Seat',
        };
    }
}
