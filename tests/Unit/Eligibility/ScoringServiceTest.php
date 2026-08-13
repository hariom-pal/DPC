<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsRuleSets;

/**
 * CR grade -> marks (PPT C4, requirements §5.1–§5.2) — M5 Phase 1.
 *
 * No database and no container: the engine is a pure function, and these
 * tests prove it by never booting the application.
 */
class ScoringServiceTest extends TestCase
{
    use BuildsRuleSets;

    private ScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new ScoringService;
    }

    private function officer(array $crGrades, ?int $tribalYears = null): OfficerRecord
    {
        return new OfficerRecord(name: 'Test Officer', crGrades: $crGrades, tribalAreaServiceYears: $tribalYears);
    }

    public function test_each_grade_converts_to_its_configured_mark(): void
    {
        $score = $this->scoring->score(
            $this->officer([2013 => 'क+', 2014 => 'क', 2015 => 'ख', 2016 => 'ग', 2017 => 'घ']),
            $this->ruleSet(),
        );

        $this->assertSame([2017 => 0, 2016 => 1, 2015 => 2, 2014 => 3, 2013 => 4], $score->marksByYear);
        $this->assertSame(10, $score->baseTotal);
        $this->assertSame(10, $score->total);
    }

    public function test_only_the_five_most_recent_available_years_are_scored(): void
    {
        // Architect Decision 1. The S18 chart carries six CR columns; the
        // oldest must be excluded from the score.
        $score = $this->scoring->score(
            $this->officer([2012 => 'क+', 2013 => 'क', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क+']),
            $this->ruleSet(),
        );

        $this->assertSame([2017, 2016, 2015, 2014, 2013], $score->yearsUsed);
        $this->assertArrayNotHasKey(2012, $score->marksByYear);
        $this->assertSame(15, $score->total);
        $this->assertFalse($score->hasIncompleteWindow());
    }

    public function test_the_scoring_window_is_configuration_driven(): void
    {
        $score = $this->scoring->score(
            $this->officer([2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+']),
            $this->ruleSet(['scoringYears' => 3]),
        );

        $this->assertSame([2017, 2016, 2015], $score->yearsUsed);
        $this->assertSame(12, $score->total);
    }

    public function test_years_recorded_without_a_grade_are_skipped_not_scored_as_zero(): void
    {
        // A missing CR means "no assessment on record" — the situation a
        // Circulation DPC exists for. Scoring it as zero would silently turn
        // an administrative gap into a failed officer.
        $score = $this->scoring->score(
            $this->officer([2013 => 'क', 2014 => null, 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            $this->ruleSet(),
        );

        $this->assertSame([2017, 2016, 2015, 2013], $score->yearsUsed);
        $this->assertSame([2014], $score->yearsWithoutGrade);
        $this->assertSame(12, $score->total);
        $this->assertTrue($score->hasIncompleteWindow());
    }

    public function test_an_unrecognised_grade_is_treated_as_ungraded_rather_than_zero(): void
    {
        $score = $this->scoring->score(
            $this->officer([2016 => 'A+', 2017 => 'क']),
            $this->ruleSet(),
        );

        $this->assertSame([2017], $score->yearsUsed);
        $this->assertSame([2016], $score->yearsWithoutGrade);
        $this->assertSame(3, $score->total);
    }

    public function test_fewer_years_than_the_window_is_flagged_as_incomplete(): void
    {
        $score = $this->scoring->score(
            $this->officer([2016 => 'क', 2017 => 'क']),
            $this->ruleSet(),
        );

        $this->assertTrue($score->hasIncompleteWindow());
        $this->assertSame(2, $score->gradedYearCount());
    }

    public function test_tribal_area_service_adds_a_single_bonus_regardless_of_years(): void
    {
        // §5.2: "+1 (else NO CHANGE)" — one bonus, not one per year.
        $base = $this->officer([2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $oneYear = $this->officer([2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'], 1);
        $sixYears = $this->officer([2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'], 6);

        $this->assertSame(15, $this->scoring->score($base, $this->ruleSet())->total);
        $this->assertSame(16, $this->scoring->score($oneYear, $this->ruleSet())->total);
        $this->assertSame(16, $this->scoring->score($sixYears, $this->ruleSet())->total);
    }

    public function test_zero_recorded_tribal_years_earns_no_bonus(): void
    {
        $score = $this->scoring->score(
            $this->officer([2016 => 'क', 2017 => 'क'], 0),
            $this->ruleSet(),
        );

        $this->assertSame(0, $score->tribalAreaBonus);
    }

    public function test_the_bonus_amount_is_configuration_driven(): void
    {
        $score = $this->scoring->score(
            $this->officer([2017 => 'क'], 2),
            $this->ruleSet(['tribalBonus' => 3]),
        );

        $this->assertSame(3, $score->tribalAreaBonus);
        $this->assertSame(6, $score->total);
    }

    public function test_an_officer_with_no_cr_at_all_scores_zero_without_error(): void
    {
        $score = $this->scoring->score($this->officer([]), $this->ruleSet());

        $this->assertSame([], $score->yearsUsed);
        $this->assertSame(0, $score->total);
        $this->assertTrue($score->hasIncompleteWindow());
        $this->assertNull($score->mostRecentYear());
    }

    public function test_scoring_is_deterministic(): void
    {
        // A stored calculation must be reproducible years later.
        $officer = $this->officer([2013 => 'क+', 2014 => 'ख', 2015 => 'क', 2016 => 'ग', 2017 => 'क'], 1);

        $first = $this->scoring->score($officer, $this->ruleSet());
        $second = $this->scoring->score($officer, $this->ruleSet());

        $this->assertSame($first->toArray(), $second->toArray());
    }
}
