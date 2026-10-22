<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Services\PromotionCrRules;

/**
 * A candidate's CR, Property Return and Loyalty records checked against the Class
 * Master promotion rule for the proposal's promotion (from class → to class) and DPC
 * year.
 *
 * Year 1 of the rule is the year just before the DPC year. Points come from the
 * Eligible CR Grades valid on 1 January of the DPC year (grade code → points, rule 3.4).
 * A CR year's points are its CR's: the highest available authority's remark
 * (Accepting, else Reviewing, else Reporting); several CRs in a year are averaged and
 * the decimal dropped (rule 3.1).
 *
 * The same rule decides the DPC calculation (DpcCalculationService, since 2026-10-21);
 * here it is shown per candidate while the details are entered. It does not stop a
 * proposal from being submitted — the calculation records the outcome.
 */
final class PromotionRuleCheck
{
    /** @var array<int, array<string, int>> */
    private array $pointsCache = [];

    public function __construct(
        private readonly PromotionCrRules $rules,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * @return array{
     *     rule: PromotionCrRule|null,
     *     status: 'meets'|'does_not_meet'|'no_rule',
     *     years: list<array{year: int, points: int|null, min: int|null, cr_required: bool, cr_ok: bool, property_required: bool, property_ok: bool, loyalty_required: bool, loyalty_ok: bool}>,
     *     total: int|null,
     *     required_total: int|null,
     *     findings: list<string>
     * }
     */
    public function check(DpcEvaluationRow $row, DpcProposal $proposal): array
    {
        $year = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
        $from = PromotionCrRules::classCode($proposal->promotionFromPost?->class_level);
        $to = PromotionCrRules::classCode($proposal->promotionToPost?->class_level);
        // One proposal's candidates share the rule and the points; a list asks once.
        $rule = $this->rules->ruleForProposal($proposal);

        if ($rule === null) {
            return ['rule' => null, 'status' => 'no_rule', 'years' => [], 'total' => null, 'required_total' => null, 'findings' => [
                (string) __('dpc.employee_details.rule.no_rule', ['from' => $from ?? '—', 'to' => $to ?? '—', 'year' => $year]),
            ]];
        }

        $row->loadMissing(['crGrades', 'propertyFlags', 'loyaltyFlags']);
        $points = $this->pointsCache[$year] ??= $this->rules->pointsForYear($year);
        $byYear = $rule->years->keyBy('years_before');
        $findings = [];
        $years = [];

        for ($before = 1; $before <= $rule->span(); $before++) {
            $calendar = $year - $before;
            $requirement = $byYear->get($before);

            $crInWindow = $before <= $rule->cr_years;
            $crRequired = $crInWindow && (bool) $requirement?->cr_mandatory;
            $min = $crInWindow ? $requirement?->cr_min_marks : null;

            $cr = $row->crGrades->firstWhere('year', $calendar);
            $yearPoints = $cr instanceof DpcEvaluationCrGrade ? $this->pointsOf($cr, $points, $findings) : null;

            $crOk = true;

            if ($crRequired && $yearPoints === null) {
                $crOk = false;
                $findings[] = (string) __('dpc.employee_details.rule.cr_missing', ['year' => $calendar]);
            } elseif ($min !== null && $yearPoints !== null && $yearPoints < $min) {
                $crOk = false;
                $findings[] = (string) __('dpc.employee_details.rule.cr_below_min', ['year' => $calendar, 'points' => $yearPoints, 'min' => $min]);
            }

            $propertyRequired = $before <= $rule->property_years && (bool) $requirement?->property_mandatory;
            $propertyOk = ! $propertyRequired || $row->propertyFlags->firstWhere('year', $calendar)?->flag === true;

            if (! $propertyOk) {
                $findings[] = (string) __('dpc.employee_details.rule.property_missing', ['year' => $calendar]);
            }

            $loyaltyRequired = $before <= $rule->loyalty_years && (bool) $requirement?->loyalty_mandatory;
            $loyaltyOk = ! $loyaltyRequired || $row->loyaltyFlags->firstWhere('year', $calendar)?->flag === true;

            if (! $loyaltyOk) {
                $findings[] = (string) __('dpc.employee_details.rule.loyalty_missing', ['year' => $calendar]);
            }

            $years[] = [
                'year' => $calendar,
                'points' => $crInWindow ? $yearPoints : null,
                'min' => $min,
                'cr_required' => $crRequired,
                'cr_ok' => $crOk,
                'property_required' => $propertyRequired,
                'property_ok' => $propertyOk,
                'loyalty_required' => $loyaltyRequired,
                'loyalty_ok' => $loyaltyOk,
            ];
        }

        // The total exactly as the DPC calculation scores it: the rule's CR years (the
        // most recent graded), plus the tribal bonus.
        $base = RuleSet::fromConfig();
        $score = $this->scoring->score(
            OfficerRecord::fromEvaluationRow($row, null, $points),
            RuleSet::forPromotionRule($base, $rule, $points, $year, $from.'-'.$to),
        );
        $required = (int) ($rule->qualifying_total ?? round((float) $rule->qualifying_average * $rule->cr_years));

        if ($score->yearsUsed !== [] && $score->total < $required) {
            $findings[] = (string) __('dpc.employee_details.rule.total_below', ['total' => $score->total, 'required' => $required]);
        }

        return [
            'rule' => $rule,
            'status' => $findings === [] ? 'meets' : 'does_not_meet',
            'years' => $years,
            'total' => $score->yearsUsed === [] ? null : $score->total,
            'required_total' => $required,
            'findings' => array_values(array_unique($findings)),
        ];
    }

    /**
     * A CR year's points (DpcEvaluationCrGrade::pointsOn), noting a grade that has
     * no points in the Eligible CR Grades.
     *
     * @param  array<string, int>  $points
     * @param  list<string>  $findings
     */
    private function pointsOf(DpcEvaluationCrGrade $cr, array $points, array &$findings): ?int
    {
        foreach ($cr->finalGrades() as $grade) {
            if (! isset($points[$grade])) {
                $findings[] = (string) __('dpc.employee_details.rule.grade_without_points', ['grade' => $grade]);

                return null;
            }
        }

        return $cr->pointsOn($points);
    }
}
