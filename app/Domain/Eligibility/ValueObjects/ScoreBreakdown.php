<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

/**
 * The arithmetic behind one officer's score, kept in full — M5 Phase 1.
 *
 * A promotion score that cannot be shown working is not defensible in a
 * service matter, so this carries the years it used, the mark it gave each
 * one, and how the total was reached — not just the total. `yearsUsed` is one
 * of the values the architect requires every calculation run to persist.
 */
final class ScoreBreakdown
{
    /**
     * @param  list<int>  $yearsUsed  most recent first
     * @param  array<int, int>  $marksByYear
     * @param  list<int>  $yearsWithoutGrade  years on the chart carrying no grade
     */
    public function __construct(
        public readonly array $yearsUsed,
        public readonly array $marksByYear,
        public readonly int $baseTotal,
        public readonly int $tribalAreaBonus,
        public readonly int $total,
        public readonly int $scoringYearsRequired,
        public readonly array $yearsWithoutGrade = [],
    ) {}

    /**
     * Fewer graded CR years than the rules score over.
     *
     * Surfaced, never acted on here: an officer missing CRs is exactly what a
     * Circulation DPC exists for (promotion held, not refused), and that
     * decision belongs to M9. Scoring an absent CR as zero would silently turn
     * an administrative gap into a failed officer.
     */
    public function hasIncompleteWindow(): bool
    {
        return count($this->yearsUsed) < $this->scoringYearsRequired;
    }

    public function gradedYearCount(): int
    {
        return count($this->yearsUsed);
    }

    /**
     * The most recent graded year in the window, or null when none is graded.
     * §5.3's class-I gate turns on this year's grade.
     */
    public function mostRecentYear(): ?int
    {
        return $this->yearsUsed[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'years_used' => $this->yearsUsed,
            'marks_by_year' => $this->marksByYear,
            'base_total' => $this->baseTotal,
            'tribal_area_bonus' => $this->tribalAreaBonus,
            'total' => $this->total,
            'scoring_years_required' => $this->scoringYearsRequired,
            'years_without_grade' => $this->yearsWithoutGrade,
            'incomplete_window' => $this->hasIncompleteWindow(),
        ];
    }
}
