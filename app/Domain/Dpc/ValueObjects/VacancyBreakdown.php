<?php

declare(strict_types=1);

namespace App\Domain\Dpc\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * One complete vacancy calculation (change register of 2026-08-27, items 1 to 8).
 *
 * A value object holding **every figure the register's summary names**, in its
 * order, plus the inputs each was derived from. Nothing here computes anything;
 * `VacancyCalculationService` does the arithmetic and hands the result over.
 *
 * **Why a value object and not an array.** The register lists nine figures that a
 * reader will compare against each other, and several of them are legitimately
 * *negative or absent* — a working strength above the sanctioned strength is a data
 * fault the screen must state rather than a value it can refuse to hold. An array
 * lets a caller read a key nobody set and get null, which on a vacancy count reads
 * as zero. Here `$breakdown->totalVacancy` is an integer or the object does not
 * exist.
 *
 * **The chair figures sit beside the vacancy figures deliberately.** Since the
 * business directive of 2026-09-02 the promotion vacancy is
 * `promotion chairs − promotion chairs held`, not a percentage of a total, so a
 * reader has to be able to see both terms of the subtraction. The rounding rule
 * for a fractional share is still unsettled (BD-33) and still applies — but now to
 * the chairs, where a whole chair either belongs to a quota or does not.
 */
final readonly class VacancyBreakdown
{
    /**
     * @param  list<array{sn: int, name: string, ground: string, detail: string}>  $contributors
     *                                                                                            Which officers added a retirement or deputation vacancy, and why.
     */
    public function __construct(
        public ?int $sanctionedStrength,
        public int $workingStrength,

        /*
         | Rows on the chart — officers **and** vacant posts (business directive,
         | 2026-09-02). Carried separately from the working strength because since
         | that directive the two differ, and it is the whole chart that the
         | sanctioned ceiling applies to.
         */
        public int $chartRows,

        public ?int $baseVacancy,
        public int $retirementVacancy,
        public int $deputationVacancy,
        public ?int $totalVacancy,
        public ?string $promotionPercentage,

        /*
         | The Recruitment Rule's share applied to the **chairs** (business
         | directive, 2026-09-02), and how many of each are substantively held.
         | Reported because the promotion vacancy is now a subtraction rather than
         | a multiplication, and a reader must be able to see both sides of it.
         */
        public ?int $promotionChairs,
        public ?int $directChairs,
        public int $promotionChairsHeld,
        public int $directChairsHeld,
        public int $deputationChairsHeld,

        public ?int $promotionVacancy,
        public ?int $directRecruitmentVacancy,
        public Carbon $periodFrom,
        public Carbon $periodTo,
        public string $roundingRule,
        public array $contributors = [],
        public ?int $sanctionedFromPostId = null,
        public ?int $recruitmentRuleId = null,
    ) {}

    /**
     * Whether the calculation could be completed at all.
     *
     * False when the Department Post Mapping carries no sanctioned strength or the
     * Recruitment Rule states no promotion share. **Both are master-data gaps
     * rather than zero vacancies**, and the difference matters: "there are no
     * vacancies" is a finding the Commission may act on, and "we cannot say" is a
     * screen waiting for the Administrator.
     */
    public function isComplete(): bool
    {
        return $this->sanctionedStrength !== null && $this->promotionPercentage !== null;
    }

    /**
     * Whether the chart leaves sanctioned posts unaccounted for.
     *
     * **The direct consequence of stating vacancy on the chart.** A chair that is
     * not on the chart has said nothing about itself — not whether anybody holds
     * it, not which quota it belongs to, not which roster point it answers to. The
     * arithmetic can still count it as vacant, because `sanctioned − held` does
     * not need the row; the breakdown cannot, because a category has to come from
     * somewhere.
     *
     * Reported rather than refused, so an officer can save a chart in progress and
     * come back to it. The submission gate is what insists.
     */
    public function chartIsIncomplete(): bool
    {
        return $this->sanctionedStrength !== null && $this->chartRows < $this->sanctionedStrength;
    }

    /**
     * Whether the chart claims more chairs of a quota than the rule allows.
     *
     * A 100%-promotion post has no direct chairs, so a row saying an officer came
     | by direct recruitment describes an appointment the rule forbids. It shows up
     * as a negative vacancy, which is a data fault rather than a finding — either
     * the row or the rule is wrong, and the screen says so instead of printing a
     * negative seat count.
     */
    public function quotaOverfilled(): bool
    {
        return ($this->promotionVacancy !== null && $this->promotionVacancy < 0)
            || ($this->directRecruitmentVacancy !== null && $this->directRecruitmentVacancy < 0);
    }

    /**
     * Whether the officer chart exceeds the sanctioned strength.
     *
     * Item 9's first validation. Reported rather than thrown, because the officer
     * looking at it needs to know which of the two numbers to correct.
     */
    public function exceedsSanctionedStrength(): bool
    {
        /*
         | **Counts rows, not officers** (business directive, 2026-09-02).
         |
         | It used to read a negative Base Vacancy, which was `Sanctioned − Working`
         | and so went below zero exactly when the chart was too long. Base is now a
         | count of vacant rows and can never be negative, which would have left this
         | permanently false — the ceiling gate silently dead. The chart describes the
         | establishment, so the test is whether it claims more chairs than exist.
         */
        return $this->sanctionedStrength !== null && $this->chartRows > $this->sanctionedStrength;
    }

    /**
     * The figures the summary screen renders, in the register's own order.
     *
     * @return list<array{key: string, value: int|string|null}>
     */
    public function summaryRows(): array
    {
        /*
         | Ordered as the arithmetic runs, so the screen reads as a derivation
         | rather than as a list of numbers: the establishment, how the rule divides
         | it, how much of each quota is actually held, and what is therefore
         | vacant.
         */
        return [
            ['key' => 'sanctioned_strength', 'value' => $this->sanctionedStrength],
            ['key' => 'working_strength', 'value' => $this->workingStrength],
            ['key' => 'promotion_percentage', 'value' => $this->promotionPercentage],
            ['key' => 'promotion_chairs', 'value' => $this->promotionChairs],
            ['key' => 'direct_chairs', 'value' => $this->directChairs],
            ['key' => 'promotion_chairs_held', 'value' => $this->promotionChairsHeld],
            ['key' => 'direct_chairs_held', 'value' => $this->directChairsHeld],
            ['key' => 'deputation_chairs_held', 'value' => $this->deputationChairsHeld],
            ['key' => 'base_vacancy', 'value' => $this->baseVacancy],
            ['key' => 'retirement_vacancy', 'value' => $this->retirementVacancy],
            ['key' => 'deputation_vacancy', 'value' => $this->deputationVacancy],
            ['key' => 'promotion_vacancy', 'value' => $this->promotionVacancy],
            ['key' => 'direct_recruitment_vacancy', 'value' => $this->directRecruitmentVacancy],
            ['key' => 'total_vacancy', 'value' => $this->totalVacancy],
        ];
    }

    /**
     * The shape stored on `dpc_vacancy_calculations.inputs`.
     *
     * Everything a reader needs to reconstruct the run without reading five other
     * tables, which is the point of recording it at all — the sanctioned strength,
     * the promotion share and the officers' dates all live on records that change.
     *
     * @return array<string, mixed>
     */
    public function toInputs(): array
    {
        return [
            'sanctioned_strength' => $this->sanctionedStrength,
            'sanctioned_from_post_id' => $this->sanctionedFromPostId,
            'working_strength' => $this->workingStrength,
            'recruitment_rule_id' => $this->recruitmentRuleId,
            'promotion_percentage' => $this->promotionPercentage,
            'period_from' => $this->periodFrom->toDateString(),
            'period_to' => $this->periodTo->toDateString(),
            'rounding_rule' => $this->roundingRule,
            'promotion_chairs' => $this->promotionChairs,
            'direct_chairs' => $this->directChairs,
            'promotion_chairs_held' => $this->promotionChairsHeld,
            'direct_chairs_held' => $this->directChairsHeld,
            'deputation_chairs_held' => $this->deputationChairsHeld,
        ];
    }
}
