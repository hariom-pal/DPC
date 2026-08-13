<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\Services\SuperSeatService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsRuleSets;

/**
 * Super Seat (requirements §5.5, PPT S33) — M5 Phase 2.
 */
class SuperSeatServiceTest extends TestCase
{
    use BuildsRuleSets;

    private ScoringService $scoring;

    private SuperSeatService $superSeat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new ScoringService;
        $this->superSeat = new SuperSeatService;
    }

    private function assess(array $crGrades, array $ruleOverrides = [], ?int $tribalYears = null)
    {
        $officer = new OfficerRecord(name: 'Test Officer', crGrades: $crGrades, tribalAreaServiceYears: $tribalYears);
        $rules = $this->ruleSet($ruleOverrides);

        return $this->superSeat->assess($officer, $this->scoring->score($officer, $rules), $rules);
    }

    private function fiveYears(string ...$grades): array
    {
        return array_combine([2013, 2014, 2015, 2016, 2017], $grades);
    }

    public function test_five_top_grades_earn_a_super_seat(): void
    {
        $verdict = $this->assess($this->fiveYears('क+', 'क+', 'क+', 'क+', 'क+'));

        $this->assertTrue($verdict->qualifies);
        $this->assertSame(5, $verdict->yearsAtRequiredGrade);
        $this->assertStringContainsString('Super Seat', $verdict->explanation);
    }

    public function test_one_lesser_grade_disqualifies(): void
    {
        $verdict = $this->assess($this->fiveYears('क+', 'क+', 'क', 'क+', 'क+'));

        $this->assertFalse($verdict->qualifies);
        $this->assertSame(4, $verdict->yearsAtRequiredGrade);
    }

    public function test_a_sixth_older_year_below_the_top_grade_does_not_disqualify(): void
    {
        // Only the scoring window counts, so an older ordinary year is
        // irrelevant — matching S18 row 8 (US8), whose 2012 grade is क.
        $verdict = $this->assess([
            2012 => 'क', 2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+',
        ]);

        $this->assertTrue($verdict->qualifies);
    }

    public function test_an_incomplete_cr_window_can_never_qualify(): void
    {
        // Three क+ years and two missing is not the same demonstration as
        // five, and a notional promotion ahead of a senior colleague must not
        // rest on absent records.
        $verdict = $this->assess([2015 => 'क+', 2016 => 'क+', 2017 => 'क+']);

        $this->assertFalse($verdict->qualifies);
        $this->assertSame(3, $verdict->yearsAtRequiredGrade);
        $this->assertStringContainsString('only 3 are on record', $verdict->explanation);
    }

    public function test_the_required_grade_is_configuration_driven(): void
    {
        $verdict = $this->assess($this->fiveYears('क', 'क', 'क', 'क', 'क'), ['superSeatGrade' => 'क']);

        $this->assertTrue($verdict->qualifies);
        $this->assertSame('क', $verdict->requiredGrade);
    }

    public function test_the_window_length_is_configuration_driven(): void
    {
        $verdict = $this->assess(
            [2016 => 'क+', 2017 => 'क+'],
            ['scoringYears' => 2],
        );

        $this->assertTrue($verdict->qualifies);
        $this->assertSame(2, $verdict->yearsRequired);
    }

    public function test_the_rule_follows_the_grade_not_a_hardcoded_total(): void
    {
        // Both readings appear in the requirements ("all 5 CRs = क+ (sum
        // 20)") and agree only while क+ is worth 4. Revalue the grade and the
        // rule must still mean "an unbroken run of top grades".
        $verdict = $this->assess(
            $this->fiveYears('क+', 'क+', 'क+', 'क+', 'क+'),
            ['marks' => ['क+' => 9, 'क' => 3, 'ख' => 2, 'ग' => 1, 'घ' => 0]],
        );

        $this->assertTrue($verdict->qualifies);
    }

    public function test_the_tribal_bonus_does_not_create_a_super_seat(): void
    {
        // A bonus lifts the score, not the grades.
        $verdict = $this->assess($this->fiveYears('क+', 'क+', 'क+', 'क+', 'क'), tribalYears: 2);

        $this->assertFalse($verdict->qualifies);
    }

    public function test_an_officer_with_no_cr_does_not_qualify(): void
    {
        $verdict = $this->assess([]);

        $this->assertFalse($verdict->qualifies);
        $this->assertSame(0, $verdict->yearsAtRequiredGrade);
    }

    public function test_super_seat_officers_are_ordered_by_actual_seniority(): void
    {
        // §5.5 — notional seniority puts them ahead of the field, but between
        // themselves the original order stands.
        $ordered = $this->superSeat->orderByActualSeniority([
            new OfficerRecord(name: 'C', crGrades: [], serialNumber: 9),
            new OfficerRecord(name: 'A', crGrades: [], serialNumber: 2),
            new OfficerRecord(name: 'B', crGrades: [], serialNumber: 5),
        ]);

        $this->assertSame(['A', 'B', 'C'], array_map(fn ($o) => $o->name, $ordered));
    }

    public function test_officers_without_a_serial_number_sort_last_deterministically(): void
    {
        // An unstable order here would make a calculation unreproducible.
        $ordered = $this->superSeat->orderByActualSeniority([
            new OfficerRecord(name: 'Zeta', crGrades: []),
            new OfficerRecord(name: 'Alpha', crGrades: []),
            new OfficerRecord(name: 'Numbered', crGrades: [], serialNumber: 4),
        ]);

        $this->assertSame(['Numbered', 'Alpha', 'Zeta'], array_map(fn ($o) => $o->name, $ordered));
    }
}
