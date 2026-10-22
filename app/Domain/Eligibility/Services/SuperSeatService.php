<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Eligibility\ValueObjects\ScoreBreakdown;
use App\Domain\Eligibility\ValueObjects\SuperSeatVerdict;

/**
 * Super Seat (SS) — notional (काल्पनिक) seniority for an unbroken run of top
 * CR grades (requirements §5.5, PPT S33) — M5 Phase 2.
 *
 * Pure, like every other engine here.
 *
 * The rule is tested on the **grade**, not on a total of 20. Both readings
 * appear in the requirements ("all 5 CRs = क+ (sum 20)"), and they only agree
 * while क+ is worth 4: if a notification revalued the grades, a total-based
 * test would quietly stop meaning "an unbroken run of top grades", which is
 * what the rule is actually about. The score is cross-checked rather than
 * relied upon.
 *
 * A short CR window can never qualify. An officer with three क+ years and two
 * missing has not demonstrated the same thing as one with five, and a notional
 * promotion ahead of a senior colleague is exactly the outcome that must not
 * rest on absent records.
 */
class SuperSeatService
{
    public function assess(OfficerRecord $officer, ScoreBreakdown $score, RuleSet $rules): SuperSeatVerdict
    {
        $required = $rules->superSeatGrade;
        $yearsRequired = $rules->scoringYears;

        // Under a Class Master rule: points at or above the rule's super-seat points;
        // a rule without them has no Super Seat.
        $points = $rules->classRule === null ? null : $rules->classRule['super_seat_points'];

        $atRequiredGrade = 0;
        foreach ($score->yearsUsed as $year) {
            $atGrade = $rules->classRule === null
                ? ($officer->crGrades[$year] ?? null) === $required
                : $points !== null && ($score->marksByYear[$year] ?? -1) >= $points;

            if ($atGrade) {
                $atRequiredGrade++;
            }
        }

        if ($rules->classRule !== null && $points === null) {
            return new SuperSeatVerdict(
                qualifies: false,
                requiredGrade: $required,
                yearsRequired: $yearsRequired,
                yearsAtRequiredGrade: 0,
                explanation: 'The promotion rule sets no Super Seat.',
            );
        }

        if ($score->hasIncompleteWindow()) {
            return new SuperSeatVerdict(
                qualifies: false,
                requiredGrade: $required,
                yearsRequired: $yearsRequired,
                yearsAtRequiredGrade: $atRequiredGrade,
                explanation: "Super Seat requires {$yearsRequired} graded CR years, but only "
                    .$score->gradedYearCount().' are on record.',
            );
        }

        $qualifies = $atRequiredGrade === $yearsRequired;

        $explanation = $qualifies
            ? "All {$yearsRequired} CR years in the scoring window (".implode(', ', $score->yearsUsed)
                .") are {$required}, scoring {$score->baseTotal} — Super Seat, carrying notional seniority."
            : "Super Seat requires all {$yearsRequired} CR years at {$required}; {$atRequiredGrade} of "
                ."{$yearsRequired} qualify.";

        return new SuperSeatVerdict(
            qualifies: $qualifies,
            requiredGrade: $required,
            yearsRequired: $yearsRequired,
            yearsAtRequiredGrade: $atRequiredGrade,
            explanation: $explanation,
        );
    }

    /**
     * §5.5 — "among multiple SS, actual seniority decides".
     *
     * Notional seniority puts Super Seat officers ahead of the field, but not
     * ahead of each other: between them the original seniority order stands.
     * Officers without a serial number sort last, deterministically, rather
     * than in whatever order the input happened to arrive — an unstable sort
     * here would make a calculation unreproducible.
     *
     * @param  list<OfficerRecord>  $officers
     * @return list<OfficerRecord>
     */
    public function orderByActualSeniority(array $officers): array
    {
        usort($officers, static function (OfficerRecord $a, OfficerRecord $b): int {
            $left = $a->serialNumber ?? PHP_INT_MAX;
            $right = $b->serialNumber ?? PHP_INT_MAX;

            return $left <=> $right ?: strcmp($a->name, $b->name);
        });

        return $officers;
    }
}
