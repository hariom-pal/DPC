<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Master\Models\ClassMaster;

/**
 * Statutory service classes (Class I / II / III).
 *
 * **The code set stays an enum, and the label moved to the Class Master**
 * (change item 1, 2026-08-24). The two halves have different owners and that is
 * the whole reason they are now in different places:
 *
 *  - The **codes** are statutory. They are also what the M5 scoring engine keys
 *    its zone-of-consideration rules on — `scoring.zone.rules` is a map of class
 *    *pairs* — so a code that could appear at runtime would be a class the engine
 *    has no rule for. Enum, as the architect decided.
 *  - The **labels** are presentation, and a government portal that must render
 *    "वर्ग-1" on its Hindi screens cannot have them compiled in. Master, owned by
 *    the CGPSC Administrator.
 */
enum ServiceClass: string
{
    case I = 'I';
    case II = 'II';
    case III = 'III';

    /**
     * The administrator's name for this class in the active locale.
     *
     * Reads the Class Master through its per-request memo, so the thirty-odd view
     * sites that call this — several inside loops over a hundred officers — cost
     * one query for the whole request. Falls back to `Class-<code>` when the
     * master has no row, which is what a not-yet-seeded database and a unit test
     * both look like.
     */
    public function label(): string
    {
        return ClassMaster::labelFor($this->value);
    }
}
