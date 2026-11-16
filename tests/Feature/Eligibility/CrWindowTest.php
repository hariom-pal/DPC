<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Eligibility\CrWindow;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\PromotionCrRuleYear;
use Tests\TestCase;

/**
 * The officer's CR window (2026-11-16): a middle year without a counted CR adds one
 * older year, the last year never does; the rule's per-year requirements follow the
 * counted years by position, and a skipped year is still answered for (CIR).
 */
class CrWindowTest extends TestCase
{
    public function test_a_middle_year_missing_adds_one_older_year_and_the_last_year_does_not(): void
    {
        $all = CrWindow::of(2022, 5, [], 1950);
        $this->assertSame([2022, 2021, 2020, 2019, 2018], $all->years);
        $this->assertSame([], $all->skipped);

        $last = CrWindow::of(2022, 5, [2022], 1950);
        $this->assertSame([2022, 2021, 2020, 2019, 2018], $last->years);
        $this->assertSame(1, $last->positionOf(2022));

        $middle = CrWindow::of(2022, 5, [2021], 1950);
        $this->assertSame([2022, 2021, 2020, 2019, 2018, 2017], $middle->years);
        $this->assertSame([2022, 2020, 2019, 2018, 2017], $middle->counted);
        $this->assertSame([2021], $middle->skipped);
        $this->assertSame(5, $middle->positionOf(2017));
        $this->assertNull($middle->positionOf(2021));

        $this->assertSame([2022, 2021, 2020, 2019, 2018, 2017, 2016], CrWindow::of(2022, 5, [2021, 2019], 1950)->years);
        $this->assertSame([2022, 2021, 2020], CrWindow::of(2022, 5, [2021], 2020)->years, 'Never before the first year of service.');
    }

    public function test_the_rule_follows_the_counted_years_by_position_and_not_the_skipped_year(): void
    {
        $rules = $this->ruleSet();
        $officer = new OfficerRecord(name: 'A', crGrades: [], crNotCountedYears: [2021]);

        // Year 1 = 2022, year 2 = 2020 (2021 skipped) … year 5 = 2017.
        $this->assertSame(10, $rules->minimumFor(2022, $officer));
        $this->assertSame(20, $rules->minimumFor(2020, $officer));
        $this->assertSame(50, $rules->minimumFor(2017, $officer));
        $this->assertNull($rules->minimumFor(2021, $officer));
        // The skipped year is not mandatory and not CIR (2026-11-16): the counted years decide.
        $this->assertSame([2022, 2020, 2019, 2018, 2017], $rules->mandatoryCrYearsFor($officer));

        // Without the officer, or for a run stored before the window, the calendar years.
        $this->assertSame(20, $rules->minimumFor(2021));
        $old = $rules->toArray();
        unset($old['class_rule']['cr_window'], $old['class_rule']['cr_years'], $old['class_rule']['minimum_by_position'], $old['class_rule']['mandatory_positions']);
        $stored = RuleSet::fromSnapshot($old);
        $this->assertSame(20, $stored->minimumFor(2021, $officer));
        $this->assertSame([2022, 2021, 2020, 2019, 2018], $stored->mandatoryCrYearsFor($officer));

        // A run stored with the window reads back the same.
        $this->assertSame(50, RuleSet::fromSnapshot(json_decode((string) json_encode($rules->toArray()), true))->minimumFor(2017, $officer));
    }

    private function ruleSet(): RuleSet
    {
        $rule = new PromotionCrRule([
            'cr_years' => 5, 'loyalty_years' => 5, 'property_years' => 5,
            'qualifying_total' => 15, 'tribal_bonus' => 0, 'effective_from_year' => 2020,
        ]);
        $rule->id = 1;
        $rule->setRelation('years', collect(range(1, 5))->map(fn (int $before): PromotionCrRuleYear => new PromotionCrRuleYear([
            'years_before' => $before, 'cr_mandatory' => true, 'cr_min_marks' => $before * 10, 'property_mandatory' => true, 'loyalty_mandatory' => true,
        ])));

        return RuleSet::forPromotionRule(RuleSet::fromConfig(), $rule, ['A' => 3], 2023, 'I-I');
    }
}
