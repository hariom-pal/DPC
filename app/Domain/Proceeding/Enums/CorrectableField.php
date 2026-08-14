<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Enums;

/**
 * What the Commission may correct during a sitting (PPT S33 EDIT / TRIBAL) —
 * M7 Phase 2.
 *
 * A closed vocabulary rather than a free-form column name. The alternative —
 * accepting whatever field name arrives in the request — would let a caller
 * name any column on any table, which is the difference between a correction
 * and an arbitrary write.
 *
 * Which of these are actually enabled is configuration
 * (`dpc.proceeding.correctable_fields`), so a government notification can
 * narrow or widen the set without a code change. This enum bounds what
 * configuration is *able* to name.
 */
enum CorrectableField: string
{
    /**
     * S33's `TRIBAL` control. M4 stores the raw count of years served in a
     * tribal area; M5 turns any non-zero value into the single §5.2 bonus of
     * +1, so the count's magnitude does not affect a score.
     */
    case TribalAreaServiceYears = 'tribal_area_service_years';

    /** A year's CR grade. */
    case CrGrade = 'cr_grade';

    /** A year's integrity (loyalty) certificate. */
    case LoyaltyFlag = 'loyalty_flag';

    /** A year's immovable-property return. */
    case PropertyFlag = 'property_flag';

    public function label(): string
    {
        return match ($this) {
            self::TribalAreaServiceYears => 'Service in Tribal Area (years)',
            self::CrGrade => 'CR Grade',
            self::LoyaltyFlag => 'Integrity (Loyalty)',
            self::PropertyFlag => 'Immovable Property Return',
        };
    }

    /**
     * Whether the field is recorded per year. A per-year field without a year,
     * or a whole-row field with one, is a malformed correction.
     */
    public function isPerYear(): bool
    {
        return $this !== self::TribalAreaServiceYears;
    }

    /**
     * Whether the value is a yes/no return rather than a graded or counted one.
     */
    public function isBoolean(): bool
    {
        return $this === self::LoyaltyFlag || $this === self::PropertyFlag;
    }

    /**
     * The fields configuration has actually enabled.
     *
     * @return list<self>
     */
    public static function enabled(): array
    {
        /** @var list<string> $configured */
        $configured = config('dpc.proceeding.correctable_fields', []);

        return array_values(array_filter(
            self::cases(),
            static fn (self $field): bool => in_array($field->value, $configured, true),
        ));
    }
}
