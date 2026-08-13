<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Eligibility\ValueObjects\ScoreBreakdown;

/**
 * CR grades to marks (PPT C4, requirements §5.1–§5.2) — M5 Phase 1.
 *
 * A pure function of its inputs: same officer plus same RuleSet always yields
 * the same breakdown, with no database, clock, or configuration read inside.
 * That is what makes a stored calculation reproducible years later, and what
 * lets the golden cases pin the arithmetic against the PPT's own worked
 * example.
 */
class ScoringService
{
    public function score(OfficerRecord $officer, RuleSet $rules): ScoreBreakdown
    {
        [$yearsUsed, $marksByYear, $yearsWithoutGrade] = $this->selectScoringWindow($officer, $rules);

        $baseTotal = array_sum($marksByYear);

        // §5.2 — a single bonus for having served in a tribal area, not one
        // per year. "+1 (else NO CHANGE)".
        $bonus = $officer->hasTribalAreaService() ? $rules->tribalAreaBonus : 0;

        return new ScoreBreakdown(
            yearsUsed: $yearsUsed,
            marksByYear: $marksByYear,
            baseTotal: $baseTotal,
            tribalAreaBonus: $bonus,
            total: $baseTotal + $bonus,
            scoringYearsRequired: $rules->scoringYears,
            yearsWithoutGrade: $yearsWithoutGrade,
        );
    }

    /**
     * The five (configurable) most recent **available** CR years — architect
     * Decision 1.
     *
     * "Available" means the year carries an actual grade. The S18 chart holds
     * six CR year columns while only the last five are scored, and a year may
     * be listed with nothing recorded against it; both are handled here, so
     * the window is chosen from graded years rather than from however many
     * columns the chart happened to have.
     *
     * A year whose grade is not in the rule set is treated as ungraded rather
     * than silently scored as zero — an unrecognised grade is a data problem,
     * and guessing it as the worst possible mark would fail an officer for it.
     *
     * @return array{0: list<int>, 1: array<int, int>, 2: list<int>}
     */
    private function selectScoringWindow(OfficerRecord $officer, RuleSet $rules): array
    {
        $graded = [];
        $ungraded = [];

        foreach ($officer->crGrades as $year => $grade) {
            $mark = $rules->markFor($grade);

            if ($mark === null) {
                $ungraded[] = (int) $year;

                continue;
            }

            $graded[(int) $year] = $mark;
        }

        krsort($graded);
        rsort($ungraded);

        $window = array_slice($graded, 0, $rules->scoringYears, true);

        return [array_keys($window), $window, $ungraded];
    }
}
