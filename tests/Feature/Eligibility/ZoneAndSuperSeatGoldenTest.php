<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\Services\SuperSeatService;
use App\Domain\Eligibility\Services\ZoneOfConsiderationService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Enums\ServiceClass;
use Tests\TestCase;

/**
 * Golden cases for Zone of Consideration and Super Seat — M5 Phase 2.
 *
 * Run against `RuleSet::fromConfig()`, the *shipped* configuration, so they
 * prove the values the application deploys with reproduce the customer's own
 * document. Slide 17 prints the zone arithmetic outright — "पदोन्नति हेतु कुल
 * विचार्य संख्या – 2 X 2 + 4 = 8" — which makes it the one figure on the whole
 * deck that can be checked without interpretation.
 */
class ZoneAndSuperSeatGoldenTest extends TestCase
{
    private ScoringService $scoring;

    private ZoneOfConsiderationService $zone;

    private SuperSeatService $superSeat;

    private RuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scoring = new ScoringService;
        $this->zone = new ZoneOfConsiderationService;
        $this->superSeat = new SuperSeatService;
        $this->rules = RuleSet::fromConfig();
    }

    public function test_the_shipped_configuration_matches_the_ppt_zone_and_super_seat_rules(): void
    {
        $rule = $this->rules->zoneRuleFor(ServiceClass::I, ServiceClass::I);

        $this->assertSame(5, $rule['single_vacancy_size']);
        $this->assertSame(2, $rule['multiplier']);
        $this->assertSame(4, $rule['addend']);
        $this->assertSame('क+', $this->rules->superSeatGrade);

        // Class pairs other than I->I have no numeric zone (PPT "other cases").
        $this->assertNull($this->rules->zoneRuleFor(ServiceClass::II, ServiceClass::II));

        // Q5 is unruled, so the reserved expansion must ship disabled.
        $this->assertFalse($this->rules->reservedZoneExpansionEnabled);
        $this->assertSame(7, $this->rules->reservedZoneExpansionMultiplier);
    }

    public function test_the_s17_worked_zone_figure_is_reproduced_exactly(): void
    {
        // Slide 17/18: two vacancies on the Deputy Secretary post, ten Under
        // Secretaries on the seniority list. The deck prints the answer: 8.
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 2, 10, $this->rules);

        $this->assertSame(8, $zone->size);
        $this->assertSame(8, $zone->effectiveSize);
        $this->assertSame('2 × 2 + 4', $zone->formula);
        $this->assertStringContainsString('2 × 2 + 4 = 8', $zone->explanation);
    }

    public function test_a_single_vacancy_on_the_same_post_would_take_the_top_five(): void
    {
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 1, 10, $this->rules);

        $this->assertSame(5, $zone->size);
    }

    public function test_s18_row_8_is_the_super_seat_case(): void
    {
        // US8: क क+ क+ क+ क+ क+ — the 2012 grade is ordinary, but all five
        // scored years are क+, so the officer takes notional seniority.
        $officer = new OfficerRecord(
            name: 'US8',
            crGrades: [2012 => 'क', 2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            serialNumber: 8,
        );

        $score = $this->scoring->score($officer, $this->rules);
        $verdict = $this->superSeat->assess($officer, $score, $this->rules);

        $this->assertSame(20, $score->baseTotal);
        $this->assertTrue($verdict->qualifies);
    }

    public function test_no_other_s18_officer_earns_a_super_seat(): void
    {
        // The whole slide, so a rule change that hands a second officer
        // notional seniority is caught.
        $chart = [
            'US1' => [2012 => 'क+', 2013 => 'क', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क+'],
            'US2' => [2012 => 'क', 2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'],
            'US3' => [2012 => 'क', 2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'],
            'US4' => [2013 => 'क+', 2014 => 'क', 2015 => 'ख', 2016 => 'क', 2017 => 'क'],
            'US5' => [2012 => 'ख', 2013 => 'क+', 2014 => 'घ', 2015 => 'क', 2016 => 'क+', 2017 => 'क+'],
            'US6' => [2014 => 'क+', 2015 => 'क', 2016 => 'क', 2017 => 'क+'],
            'US7' => [2012 => 'क', 2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'],
            'US8' => [2012 => 'क', 2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            'US9' => [2013 => 'क+', 2014 => 'क', 2015 => 'क+', 2016 => 'क', 2017 => 'ग'],
            'US10' => [2012 => 'ख', 2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क+'],
        ];

        $superSeats = [];
        foreach ($chart as $name => $grades) {
            $officer = new OfficerRecord(name: $name, crGrades: $grades);
            $score = $this->scoring->score($officer, $this->rules);

            if ($this->superSeat->assess($officer, $score, $this->rules)->qualifies) {
                $superSeats[] = $name;
            }
        }

        $this->assertSame(['US8'], $superSeats);
    }

    public function test_the_zone_flags_a_reserved_shortfall_without_expanding_it(): void
    {
        // S16 records one clear UR vacancy and one ST vacancy expected from
        // retirement. If no ST officer clears eligibility, the 7x expansion
        // (Q5) is exactly the unruled rule that would decide the outcome.
        $zone = $this->zone->determine(
            ServiceClass::I, ServiceClass::I, 2, 10, $this->rules,
            vacanciesByCategory: ['UR' => 1, 'ST' => 1],
            eligibleByCategory: ['UR' => 4, 'ST' => 0],
        );

        $this->assertSame(8, $zone->size, 'The zone must not expand while Q5 is unruled.');
        $this->assertTrue($zone->needsBusinessDecision());
        $this->assertStringContainsString('Category ST', $zone->pendingDecisions[0]);
    }

    public function test_an_older_rule_version_stays_executable_from_its_stored_snapshot(): void
    {
        // Architect directive: older RuleSets must always remain executable,
        // and no rule version is ever deleted. A stored run carries its own
        // complete rule set, so a 2026 calculation can be re-run years later
        // under the rules that actually applied to it.
        $snapshot = $this->rules->toArray();

        $restored = RuleSet::fromSnapshot($snapshot);

        $this->assertSame($snapshot, $restored->toArray(), 'toArray() and fromSnapshot() must be exact inverses.');
        $this->assertSame($this->rules->version, $restored->version);

        // And it must still compute identically.
        $officer = new OfficerRecord(
            name: 'US1',
            crGrades: [2012 => 'क+', 2013 => 'क', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क+'],
        );

        $this->assertSame(
            $this->scoring->score($officer, $this->rules)->toArray(),
            $this->scoring->score($officer, $restored)->toArray(),
        );
    }

    public function test_a_superseded_rule_set_still_runs_under_its_own_rules(): void
    {
        // The scenario the directive exists for: a notification changes the
        // Class-I threshold, and a calculation from before it must still be
        // reproducible under the old figure.
        $snapshot = $this->rules->toArray();
        $snapshot['rule_version'] = '2025.4';
        $snapshot['qualifying_scores']['I-I'] = 13;

        $old = RuleSet::fromSnapshot($snapshot);

        $this->assertSame('2025.4', $old->version);
        $this->assertSame(13, $old->qualifyingScoreFor(ServiceClass::I, ServiceClass::I));
        // The current rules are untouched by reconstructing an old set.
        $this->assertSame(15, $this->rules->qualifyingScoreFor(ServiceClass::I, ServiceClass::I));
    }

    public function test_an_incomplete_snapshot_is_rejected_rather_than_silently_defaulted(): void
    {
        // Silently filling gaps with today's configuration would produce a
        // calculation that claims to be an old one but is not.
        $this->expectException(\InvalidArgumentException::class);

        RuleSet::fromSnapshot(['rule_version' => '2025.4']);
    }

    public function test_the_zone_result_explains_itself_for_the_stored_record(): void
    {
        // Architect directive: Explain Result must work from stored data
        // alone — no inference.
        $zone = $this->zone->determine(ServiceClass::I, ServiceClass::I, 2, 10, $this->rules);
        $stored = $zone->toArray();

        $this->assertSame(8, $stored['size']);
        $this->assertSame('2 × 2 + 4', $stored['formula']);
        $this->assertStringContainsString('= 8', $stored['explanation']);
        $this->assertIsString(json_encode($stored, JSON_THROW_ON_ERROR));
    }
}
