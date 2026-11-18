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

        [$bonus, $bonusYears, $bonusByYear] = $this->tribalBonus($officer, $rules, $marksByYear);

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
            tribalBonusByYear: $bonusByYear,
        );
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
     * @return array{0: int, 1: list<int>, 2: array<int, int>} total, years, year => bonus (oldest first)
     */
    private function tribalBonus(OfficerRecord $officer, RuleSet $rules, array $marksByYear): array
    {
        $rule = $rules->classRule;

        // No bonus where the rule gives none, or says it does not apply (2026-11-01).
        if ($rules->tribalAreaBonus <= 0) {
            return [0, [], []];
        }

        // Kept only to reproduce calculations recorded under the PPT's single bonus
        // (runs without a Class Master rule, or recorded before the per-year terms). No
        // rule the Promotion Rule Master records today takes this path.
        if ($rule === null || $rule['tribal_bonus_basis'] !== 'year') {
            return [$officer->hasTribalAreaService() ? $rules->tribalAreaBonus : 0, [], []];
        }

        $byYear = [];

        foreach ($marksByYear as $year => $mark) {
            // A year of several CRs: every CR upgraded on its own (2026-11-18).
            $reports = $officer->crReportsByYear[$year] ?? [];
            if (count($reports) > 1) {
                $upgraded = $this->upgradedYearMark($reports, $rules, $rule);
                if ($upgraded !== null && $upgraded > $mark) {
                    $byYear[(int) $year] = $upgraded - $mark;
                }

                continue;
            }

            if (($officer->tribalAreaByYear[$year] ?? null) === true && $this->earnsTribalBonus($mark, $rule)) {
                $byYear[(int) $year] = $rules->tribalAreaBonus;
            }
        }

        ksort($byYear);

        return [array_sum($byYear), array_keys($byYear), $byYear];
    }

    /**
     * Whether a CR (or a year) at these points earns the tribal bonus: at the grades
     * the rule lists (2026-11-01), or, on a rule without a list, not at the highest
     * or the lowest grade's points.
     *
     * @param  array<string, mixed>  $rule
     */
    private function earnsTribalBonus(int $mark, array $rule): bool
    {
        if ($rule['tribal_bonus_grades'] !== null) {
            return in_array($mark, $rule['tribal_bonus_grades'], true);
        }

        return ! ($rule['tribal_bonus_skips_extremes'] && ($mark === $rule['highest_points'] || $mark === $rule['lowest_points']));
    }

    /**
     * A year of several CRs (2026-11-18: "upgrade every CR as per the tribal posting
     * rule"): each CR served in a tribal area, at a grade that earns it, gets the
     * bonus on its own points — never above the highest grade — and the year is then
     * the nearest grade at or below the average of the CRs, as for any year of
     * several CRs. Null when a CR's grade is not on the scale.
     *
     * @param  list<array{grade: string, tribal: bool}>  $reports
     * @param  array<string, mixed>  $rule
     */
    private function upgradedYearMark(array $reports, RuleSet $rules, array $rule): ?int
    {
        $scale = array_values(array_map('intval', $rules->crGradeMarks));
        if ($scale === []) {
            return null;
        }
        $highest = max($scale);
        $points = [];

        foreach ($reports as $report) {
            $mark = $rules->markFor($report['grade']);
            if ($mark === null) {
                return null;
            }

            $points[] = $report['tribal'] && $this->earnsTribalBonus($mark, $rule)
                ? min($highest, $mark + $rules->tribalAreaBonus)
                : $mark;
        }

        $average = array_sum($points) / count($points);
        $below = array_filter($scale, fn (int $value): bool => $value <= $average);

        return $below === [] ? min($scale) : max($below);
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
