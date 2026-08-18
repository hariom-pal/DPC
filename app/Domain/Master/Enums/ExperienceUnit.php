<?php

declare(strict_types=1);

namespace App\Domain\Master\Enums;

/**
 * The unit a recruitment rule's minimum experience is expressed in.
 *
 * `min_experience_years` used to be a bare number, which read as "5" on screen
 * and left the user to guess. Most Schedule-IV requirements are in years, but
 * some short qualifying periods are published in months, and rounding those up
 * to a year changes who is eligible.
 */
enum ExperienceUnit: string
{
    case Years = 'years';
    case Months = 'months';

    public function label(): string
    {
        return match ($this) {
            self::Years => 'Years',
            self::Months => 'Months',
        };
    }

    /**
     * "5 Years" / "1 Year" / "18 Months" — the singular matters on a form a
     * government user reads without training.
     */
    public function labelFor(int $value): string
    {
        $singular = match ($this) {
            self::Years => 'Year',
            self::Months => 'Month',
        };

        return $value === 1 ? $singular : $this->label();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $unit): string => $unit->value, self::cases());
    }
}
