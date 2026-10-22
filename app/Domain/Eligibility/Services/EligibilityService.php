<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\EligibilityVerdict;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Eligibility\ValueObjects\ScoreBreakdown;
use App\Enums\ServiceClass;

/**
 * The hard eligibility gates and the qualifying score (PPT C4–C6,
 * requirements §5.3–§5.4) — M5 Phase 1.
 *
 * Pure, like ScoringService: no database, no clock, no configuration read
 * inside. Every threshold and grade comes from the RuleSet handed in.
 *
 * The gates are evaluated in the PPT's own order and every failure is
 * recorded, not just the first — an officer refused promotion is entitled to
 * know all the grounds, and a CAT/HC challenge will ask for them.
 */
class EligibilityService
{
    public function assess(
        OfficerRecord $officer,
        ScoreBreakdown $score,
        ServiceClass $fromClass,
        ServiceClass $toClass,
        RuleSet $rules,
    ): EligibilityVerdict {
        $required = $rules->qualifyingScoreFor($fromClass, $toClass);
        $reasons = [];

        $this->applyDisqualifyingGradeGate($officer, $score, $rules, $reasons);
        $this->applyLastYearGate($officer, $score, $fromClass, $toClass, $rules, $reasons);
        $this->applyYearMinimumGate($score, $rules, $reasons);

        $failedOnScoreOnly = $reasons === [] && $score->total < $required;

        if ($score->total < $required) {
            $reasons[] = "Qualifying score not met: scored {$score->total}, requires {$required} for a "
                ."Class-{$fromClass->value} to Class-{$toClass->value} promotion.";
        }

        // Q5/Q6 — WAITING FOR BUSINESS DECISION (architect ruling: do not
        // guess). The relaxation is neither applied nor refused on its merits;
        // this only records that this officer is one the unruled rule could
        // have changed the answer for, so those cases can be listed and
        // decided rather than silently resolved.
        $relaxationPending = ! $rules->reservedRelaxationEnabled
            && $failedOnScoreOnly
            && $officer->isReservedCategory
            && $score->total >= $rules->reservedRelaxationFloor;

        if ($relaxationPending) {
            $reasons[] = 'Reserved-category relaxation may apply (score is at or above the relaxed floor of '
                .$rules->reservedRelaxationFloor.'), but the relaxation rule is awaiting a business decision '
                .'(Q5/Q6) and has not been applied.';
        }

        $eligible = $reasons === [];

        return new EligibilityVerdict(
            eligible: $eligible,
            remark: $eligible ? RemarkCode::Eligible : RemarkCode::NotEligible,
            requiredScore: $required,
            achievedScore: $score->total,
            failureReasons: $reasons,
            reservedRelaxationPending: $relaxationPending,
            incompleteCrWindow: $score->hasIncompleteWindow(),
        );
    }

    /**
     * §5.3 — any CR in the scoring window at the disqualifying grade fails
     * outright, regardless of the total.
     *
     * @param  list<string>  $reasons
     */
    private function applyDisqualifyingGradeGate(
        OfficerRecord $officer,
        ScoreBreakdown $score,
        RuleSet $rules,
        array &$reasons,
    ): void {
        $failing = [];

        foreach ($score->yearsUsed as $year) {
            if (($officer->crGrades[$year] ?? null) === $rules->disqualifyingGrade) {
                $failing[] = $year;
            }
        }

        if ($failing !== []) {
            sort($failing);
            $reasons[] = "CR grade {$rules->disqualifyingGrade} recorded in ".implode(', ', $failing)
                .' — an officer with this grade in the scoring window is not eligible.';
        }
    }

    /**
     * The Class Master rule's minimum points for a CR year (2026-10-21): a year in
     * the scoring window below it is not eligible, whatever the total.
     *
     * @param  list<string>  $reasons
     */
    private function applyYearMinimumGate(ScoreBreakdown $score, RuleSet $rules, array &$reasons): void
    {
        foreach ($score->yearsUsed as $year) {
            $minimum = $rules->minimumFor($year);
            $mark = $score->marksByYear[$year] ?? null;

            if ($minimum !== null && $mark !== null && $mark < $minimum) {
                $reasons[] = "CR {$year} scored {$mark}, below the minimum of {$minimum} the promotion rule sets for that year.";
            }
        }
    }

    /**
     * §5.3 — for CLASS-I to CLASS-I, the most recent CR must not be ग.
     *
     * Which class pairs carry this gate, and which grade it forbids, come from
     * configuration: another pair can be given the same gate by notification
     * without touching this method.
     *
     * @param  list<string>  $reasons
     */
    private function applyLastYearGate(
        OfficerRecord $officer,
        ScoreBreakdown $score,
        ServiceClass $fromClass,
        ServiceClass $toClass,
        RuleSet $rules,
        array &$reasons,
    ): void {
        $forbidden = $rules->lastYearForbiddenGradeFor($fromClass, $toClass);
        $mostRecent = $score->mostRecentYear();

        if ($forbidden === null || $mostRecent === null) {
            return;
        }

        if (($officer->crGrades[$mostRecent] ?? null) === $forbidden) {
            $reasons[] = "Most recent CR ({$mostRecent}) is {$forbidden} — for a Class-{$fromClass->value} to "
                ."Class-{$toClass->value} promotion the latest CR may not be {$forbidden}.";
        }
    }
}
