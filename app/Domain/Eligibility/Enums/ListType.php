<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Enums;

/**
 * Which list an officer lands on (§5.7 — मुख्य सूचि / अनुपूरक सूचि) —
 * M5 Phase 3.
 *
 * `None` is not a third list. It records that the officer was calculated and
 * placed on neither — because they were not eligible, fell outside the zone,
 * or their category had no vacancy. Every officer on the chart gets a result
 * row, so the absence of a name from both lists is itself explained rather
 * than being a silent omission.
 */
enum ListType: string
{
    case Main = 'main';
    case Waiting = 'waiting';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main List (मुख्य सूचि)',
            self::Waiting => 'Waiting List (अनुपूरक सूचि)',
            self::None => 'Not Listed',
        };
    }
}
