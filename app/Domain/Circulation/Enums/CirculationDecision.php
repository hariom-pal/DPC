<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Enums;

/**
 * The Commission's decision on one held officer (PPT S45, S48) — M9 Phase 2.
 *
 * **Two decisions, not three, and the absence of a third is deliberate.**
 * S45 describes a decision taken *once the resolution is received*; it never
 * describes the Commission **refusing** a promotion on the strength of a
 * circulation. Adding a `refused` case would invent a business rule (AD-2),
 * and it would be the rule with the largest consequence in this milestone —
 * a refusal on a published list disturbs everyone below the refused officer.
 *
 * What happens when the supplied record is itself adverse — a CR of `घ`, a
 * property declaration the department cannot accept — is **BD-12**, raised
 * rather than answered.
 */
enum CirculationDecision: string
{
    /** The promotion proceeds. The hold is released when the note is signed. */
    case Consented = 'consented';

    /** Sent back for more evidence. The hold stands and the officer stays held. */
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Consented => 'Consented — the promotion proceeds',
            self::Returned => 'Returned for further evidence',
        };
    }
}
