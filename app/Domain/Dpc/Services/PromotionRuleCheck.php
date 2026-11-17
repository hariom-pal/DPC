<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
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
     *     status: 'meets'|'cir'|'does_not_meet'|'no_rule',
     *     years: list<array{year: int, points: int|null, min: int|null, cr_required: bool, cr_ok: bool, cr_skipped: bool, cr_outside: bool, property_required: bool, property_ok: bool, loyalty_required: bool, loyalty_ok: bool}>,
     *     total: int|null,
     *     required_total: int|null,
     *     findings: list<string>,
     *     holds?: list<string>
     * }
     */
    public function check(DpcEvaluationRow $row, DpcProposal $proposal): array
    {
        $year = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
        $from = PromotionCrRules::classCode($proposal->promotionFromPost?->class_level);
        $to = PromotionCrRules::classCode($proposal->promotionToPost?->class_level);
        // One proposal's candidates share the rule and the points; a list asks once.
        $rule = $this->rules->ruleForProposal($proposal);

        // The employee details' Unfit grounds (2026-11-14) make the officer Unfit whatever the rule says.
        $unfit = $row->unfitReasons();

        if ($rule === null) {
            return ['rule' => null, 'status' => $unfit !== [] ? 'does_not_meet' : 'no_rule', 'years' => [], 'total' => null, 'required_total' => null, 'holds' => [], 'findings' => [
                ...$unfit,
                (string) __('dpc.employee_details.rule.no_rule', ['from' => $from ?? '—', 'to' => $to ?? '—', 'year' => $year]),
            ]];
        }

        $row->loadMissing(['crGrades', 'propertyFlags', 'loyaltyFlags']);
        $points = $this->pointsCache[$year] ??= $this->rules->pointsForYear($year);
        $byYear = $rule->years->keyBy('years_before');
        $findings = $unfit;
        $holds = [];
        $years = [];

        /*
         | The officer's own CR window (2026-11-16), as the calculation takes it: a
         | middle year without a counted CR is skipped and one older year joins; the
         | rule's per-year requirements follow the counted years by position (year 1
         | = the latest counted). A skipped year is still answered for (CIR when
         | justified). The Property Return keeps its calendar years; Integrity is
         | given with each CR, so it follows the counted CR years.
         */
        $base = RuleSet::fromConfig();
        $ruleSet = RuleSet::forPromotionRule($base, $rule, $points, $year, $from.'-'.$to);
        $officer = OfficerRecord::fromEvaluationRow($row, null, $points);
        $window = $ruleSet->crWindowFor($officer);
        $calendarYears = range($year - 1, $year - $rule->span());
        // Every CR year recorded below is listed too (2026-11-16); a year outside the
        // window is shown, not judged.
        $recordedYears = $row->crGrades->pluck('year')->map(fn ($recorded): int => (int) $recorded)->all();
        $rows = array_values(array_unique([...($window->years ?? []), ...$calendarYears, ...$recordedYears]));
        rsort($rows);

        foreach ($rows as $calendar) {
            $before = $year - $calendar;
            $position = $window?->positionOf($calendar);
            $skipped = in_array($calendar, $window->skipped ?? [], true);
            $crRequirement = $position === null ? null : $byYear->get($position);

            $crInWindow = $skipped || ($position !== null && $position <= $rule->cr_years);
            // A skipped middle year is not required (2026-11-16): an older year is counted
            // in its place, so it is neither CIR nor a finding.
            // The year just before the DPC year recorded missing is required (MoM point 11).
            $latestMissing = $calendar === $year - 1 && $row->crGrades->firstWhere('year', $calendar)?->put_up === false;
            $crRequired = ! $skipped && $crInWindow && ((bool) $crRequirement?->cr_mandatory || $latestMissing);
            $min = $position !== null && $position <= $rule->cr_years ? $crRequirement?->cr_min_marks : null;

            $cr = $row->crGrades->firstWhere('year', $calendar);
            $yearPoints = $cr instanceof DpcEvaluationCrGrade ? $this->pointsOf($cr, $points, $findings) : null;

            $crOk = ! $skipped;

            if ($crRequired && $yearPoints === null) {
                $crOk = false;
                // Missing and justified: CIR, as the calculation holds it (2026-11-05).
                // A reason that makes the officer Unfit is already among the findings: not CIR (2026-11-15).
                if ($this->unfitGap($row, DpcEvaluationRecordGap::KIND_CR, $calendar)) {
                    // nothing held
                } elseif ($calendar === $year - 1 && $this->justified($row, DpcEvaluationRecordGap::KIND_CR, $calendar)) {
                    // The latest CR missing goes to a Circulation DPC (MoM point 11).
                    $holds[] = (string) __('dpc.employee_details.rule.cr_circulation', ['year' => $calendar]);
                } elseif ($this->justified($row, DpcEvaluationRecordGap::KIND_CR, $calendar)) {
                    $holds[] = (string) __('dpc.employee_details.rule.cr_cir', ['year' => $calendar]);
                } else {
                    $findings[] = (string) __('dpc.employee_details.rule.cr_missing', ['year' => $calendar]);
                }
            } elseif ($min !== null && $yearPoints !== null && $yearPoints < $min) {
                $crOk = false;
                $findings[] = (string) __('dpc.employee_details.rule.cr_below_min', ['year' => $calendar, 'points' => $yearPoints, 'min' => $min]);
            }

            $propertyRequirement = $byYear->get($before);
            $propertyRequired = $before <= $rule->property_years && (bool) $propertyRequirement?->property_mandatory;
            $propertyOk = ! $propertyRequired || $row->propertyFlags->firstWhere('year', $calendar)?->flag === true;

            if (! $propertyOk) {
                if ($this->unfitGap($row, DpcEvaluationRecordGap::KIND_PROPERTY, $calendar)) {
                    // nothing held: the officer is Unfit
                } elseif ($this->justified($row, DpcEvaluationRecordGap::KIND_PROPERTY, $calendar)) {
                    $holds[] = (string) __('dpc.employee_details.rule.property_cir', ['year' => $calendar]);
                } else {
                    $findings[] = (string) __('dpc.employee_details.rule.property_missing', ['year' => $calendar]);
                }
            }

            $loyaltyRequired = $position !== null && $position <= $rule->loyalty_years && (bool) $crRequirement?->loyalty_mandatory;
            $loyaltyOk = ! $loyaltyRequired || $row->loyaltyFlags->firstWhere('year', $calendar)?->flag === true;

            if (! $loyaltyOk) {
                $findings[] = (string) __('dpc.employee_details.rule.loyalty_missing', ['year' => $calendar]);
            }

            $years[] = [
                'year' => $calendar,
                'points' => $yearPoints,
                'min' => $min,
                'cr_required' => $crRequired,
                'cr_ok' => $crOk,
                'cr_skipped' => $skipped,
                // Recorded, but outside the officer's CR years: shown, not counted (2026-11-16).
                'cr_outside' => ! $crInWindow && $cr instanceof DpcEvaluationCrGrade,
                'property_required' => $propertyRequired,
                'property_ok' => $propertyOk,
                'loyalty_required' => $loyaltyRequired,
                'loyalty_ok' => $loyaltyOk,
            ];
        }

        // The total exactly as the DPC calculation scores it: the rule's CR years (the
        // most recent graded), plus the tribal bonus.
        $score = $this->scoring->score($officer, $ruleSet);
        $required = (int) ($rule->qualifying_total ?? round((float) $rule->qualifying_average * $rule->cr_years));

        if ($score->yearsUsed !== [] && $score->total < $required) {
            $findings[] = (string) __('dpc.employee_details.rule.total_below', ['total' => $score->total, 'required' => $required]);
        }

        return [
            'rule' => $rule,
            'status' => match (true) {
                $findings !== [] => 'does_not_meet',
                $holds !== [] => 'cir',
                default => 'meets',
            },
            'holds' => array_values(array_unique($holds)),
            'years' => $years,
            'total' => $score->yearsUsed === [] ? null : $score->total,
            'required_total' => $required,
            'findings' => array_values(array_unique($findings)),
        ];
    }

    /** A mandatory record missing with its reason and document (rule 3.2). */
    /** A gap whose reason makes the officer Unfit ("appointed in this year"). */
    private function unfitGap(DpcEvaluationRow $row, string $kind, int $year): bool
    {
        return $row->recordGaps->contains(
            fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === $kind && $gap->year === $year && $gap->reason?->makes_unfit === true,
        );
    }

    private function justified(DpcEvaluationRow $row, string $kind, int $year): bool
    {
        return $row->recordGaps->contains(
            fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === $kind && $gap->year === $year && $gap->isJustified(),
        );
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
