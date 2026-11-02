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

        [$bonus, $bonusYears] = $this->tribalBonus($officer, $rules, $marksByYear);

        return new ScoreBreakdown(
            yearsUsed: $yearsUsed,
            marksByYear: $marksByYear,
            baseTotal: $baseTotal,
            tribalAreaBonus: $bonus,
            total: $baseTotal + $bonus,
            scoringYearsRequired: $rules->scoringYears,
            yearsWithoutGrade: $yearsWithoutGrade,
            tribalBonusYears: $bonusYears,
            // Each tribal year's bonus beside that year's CR points (2026-11-01).
            tribalBonusByYear: $this->bonusByYear($bonusYears, $rules->tribalAreaBonus),
        );
    }

    /**
     * @param  list<int>  $years
     * @return array<int, int> year => bonus, oldest first
     */
    private function bonusByYear(array $years, int $bonus): array
    {
        $byYear = array_fill_keys($years, $bonus);
        ksort($byYear);

        return $byYear;
    }

    /**
     * The tribal posting bonus.
     *
     * PPT §5.2: a single bonus for having served in a tribal area, not one per year —
     * "+1 (else NO CHANGE)". A Class Master rule on the per-year basis (2026-10-21)
     * gives it for each scored CR year served in a tribal area — only at the grades the
     * rule lists (2026-11-01), or, on a rule without a list, not for a year whose points
     * are the highest or the lowest grade's.
     *
     * @param  array<int, int>  $marksByYear
     * @return array{0: int, 1: list<int>}
     */
    private function tribalBonus(OfficerRecord $officer, RuleSet $rules, array $marksByYear): array
    {
        $rule = $rules->classRule;

        // No bonus where the rule gives none, or says it does not apply (2026-11-01).
        if ($rules->tribalAreaBonus <= 0) {
            return [0, []];
        }

        // Kept only to reproduce calculations recorded under the PPT's single bonus
        // (runs without a Class Master rule, or recorded before the per-year terms). No
        // rule the Promotion Rule Master records today takes this path.
        if ($rule === null || $rule['tribal_bonus_basis'] !== 'year') {
            return [$officer->hasTribalAreaService() ? $rules->tribalAreaBonus : 0, []];
        }

        $years = [];

        foreach ($marksByYear as $year => $mark) {
            if (($officer->tribalAreaByYear[$year] ?? null) !== true) {
                continue;
            }

            // The rule's grade list, where it has one (2026-11-01); otherwise the older
            // "not at the highest or lowest grade".
            if ($rule['tribal_bonus_grades'] !== null) {
                if (! in_array($mark, $rule['tribal_bonus_grades'], true)) {
                    continue;
                }
            } elseif ($rule['tribal_bonus_skips_extremes'] && ($mark === $rule['highest_points'] || $mark === $rule['lowest_points'])) {
                continue;
            }

            $years[] = (int) $year;
        }

        return [$rules->tribalAreaBonus * count($years), $years];
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
            // Under a Class Master rule a year's points come from its CRs, so a year
            // of several CRs scores their average even where no single grade has it.
            $mark = $officer->crPoints[$year] ?? $rules->markFor($grade);

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
