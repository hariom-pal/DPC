<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

/**
 * Whether an officer earns a Super Seat (SS) — notional (काल्पनिक) seniority
 * for an unbroken run of top CR grades (requirements §5.5) — M5 Phase 2.
 *
 * `explanation` is carried so a stored calculation can justify a notional
 * promotion over a senior colleague from its own data, with no inference.
 * That is the outcome most likely to be challenged.
 */
final class SuperSeatVerdict
{
    public function __construct(
        public readonly bool $qualifies,
        public readonly string $requiredGrade,
        public readonly int $yearsRequired,
        public readonly int $yearsAtRequiredGrade,
        public readonly string $explanation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'qualifies' => $this->qualifies,
            'required_grade' => $this->requiredGrade,
            'years_required' => $this->yearsRequired,
            'years_at_required_grade' => $this->yearsAtRequiredGrade,
            'explanation' => $this->explanation,
        ];
    }
}
