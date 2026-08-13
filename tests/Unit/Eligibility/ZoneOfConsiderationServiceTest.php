<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Services\ZoneOfConsiderationService;
use App\Enums\ServiceClass;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsRuleSets;

/**
 * Zone of Consideration (PPT S17/S18, requirements §5.6) — M5 Phase 2.
 */
class ZoneOfConsiderationServiceTest extends TestCase
{
    use BuildsRuleSets;

    private ZoneOfConsiderationService $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = new ZoneOfConsiderationService;
    }

    public function test_a_single_vacancy_takes_the_top_five(): void
    {
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 1, 20, $this->ruleSet());

        $this->assertSame(5, $zone->size);
        $this->assertSame(5, $zone->effectiveSize);
        $this->assertStringContainsString('top 5', $zone->formula);
    }

    public function test_more_than_one_vacancy_uses_the_two_x_plus_four_formula(): void
    {
        // The PPT's own worked example: 2 vacancies -> 2 x 2 + 4 = 8.
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 2, 20, $this->ruleSet());

        $this->assertSame(8, $zone->size);
        $this->assertSame('2 × 2 + 4', $zone->formula);
    }

    public function test_the_formula_scales_with_vacancies(): void
    {
        $rules = $this->ruleSet();

        $this->assertSame(10, $this->zone->determine(ServiceClass::I, ServiceClass::I, 3, 50, $rules)->size);
        $this->assertSame(12, $this->zone->determine(ServiceClass::I, ServiceClass::I, 4, 50, $rules)->size);
        $this->assertSame(24, $this->zone->determine(ServiceClass::I, ServiceClass::I, 10, 50, $rules)->size);
    }

    public function test_the_formula_is_configuration_driven(): void
    {
        $rules = $this->ruleSet(['zoneRules' => [
            'I-I' => ['single_vacancy_size' => 7, 'multiplier' => 3, 'addend' => 1],
        ]]);

        $this->assertSame(7, $this->zone->determine(ServiceClass::I, ServiceClass::I, 1, 50, $rules)->size);
        $this->assertSame(10, $this->zone->determine(ServiceClass::I, ServiceClass::I, 3, 50, $rules)->size);
    }

    public function test_a_class_pair_without_a_rule_is_uncapped(): void
    {
        // PPT "other cases": every officer meeting the experience
        // qualification is considered, with no arithmetic cap.
        $zone = $this->zone->determine(ServiceClass::II, ServiceClass::II, 3, 12, $this->ruleSet());

        $this->assertNull($zone->size);
        $this->assertTrue($zone->isUncapped());
        $this->assertSame(12, $zone->effectiveSize);
    }

    public function test_the_zone_cannot_exceed_the_officers_actually_available(): void
    {
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 4, 6, $this->ruleSet());

        $this->assertSame(12, $zone->size);
        $this->assertSame(6, $zone->effectiveSize);
        $this->assertTrue($zone->isTruncatedByAvailability());
        $this->assertStringContainsString('Only 6 officer(s) are available', $zone->explanation);
    }

    public function test_no_vacancy_means_no_zone(): void
    {
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 0, 20, $this->ruleSet());

        $this->assertSame(0, $zone->size);
        $this->assertSame(0, $zone->effectiveSize);
    }

    public function test_the_zone_explains_its_own_arithmetic(): void
    {
        // Architect directive: a stored result must be explainable from its
        // own data. "8 officers were considered" is not defensible; the
        // arithmetic that produced 8 is.
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 2, 20, $this->ruleSet());

        $this->assertStringContainsString('2 × 2 + 4 = 8', $zone->explanation);
        $this->assertStringContainsString('Class-I to Class-I', $zone->explanation);
    }

    // ------------------------------------ Q5 — waiting for business decision

    public function test_a_reserved_shortfall_is_flagged_never_expanded(): void
    {
        $zone = $this->zone->determine(
            ServiceClass::I, ServiceClass::I, 2, 20, $this->ruleSet(),
            vacanciesByCategory: ['ST' => 1, 'UR' => 1],
            eligibleByCategory: ['ST' => 0, 'UR' => 5],
        );

        // The zone itself is untouched — nothing was expanded.
        $this->assertSame(8, $zone->size);
        $this->assertTrue($zone->needsBusinessDecision());
        $this->assertCount(1, $zone->pendingDecisions);
        $this->assertStringContainsString('Category ST', $zone->pendingDecisions[0]);
        $this->assertStringContainsString('could be expanded to 7', $zone->pendingDecisions[0]);
        $this->assertStringContainsString('awaiting a business decision', $zone->pendingDecisions[0]);
    }

    public function test_a_category_with_enough_eligible_officers_is_not_flagged(): void
    {
        $zone = $this->zone->determine(
            ServiceClass::I, ServiceClass::I, 2, 20, $this->ruleSet(),
            vacanciesByCategory: ['ST' => 1],
            eligibleByCategory: ['ST' => 3],
        );

        $this->assertFalse($zone->needsBusinessDecision());
    }

    public function test_a_category_with_no_vacancy_is_not_flagged(): void
    {
        $zone = $this->zone->determine(
            ServiceClass::I, ServiceClass::I, 2, 20, $this->ruleSet(),
            vacanciesByCategory: ['ST' => 0],
            eligibleByCategory: ['ST' => 0],
        );

        $this->assertFalse($zone->needsBusinessDecision());
    }

    public function test_nothing_is_flagged_once_the_expansion_rule_is_enabled(): void
    {
        // When Q5 is ruled and the flag is turned on, these stop being pending
        // decisions and become the engine's job.
        $zone = $this->zone->determine(
            ServiceClass::I, ServiceClass::I, 2, 20,
            $this->ruleSet(['zoneExpansionEnabled' => true]),
            vacanciesByCategory: ['ST' => 1],
            eligibleByCategory: ['ST' => 0],
        );

        $this->assertFalse($zone->needsBusinessDecision());
    }
}
