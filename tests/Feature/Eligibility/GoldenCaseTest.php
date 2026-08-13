<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\EligibilityService;
use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Enums\ServiceClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden cases — the PPT's own worked example (M5 Phase 1).
 *
 * Every row here is transcribed from slide 18 of `docs/ODPC_NEW_FINAL.pptx`:
 * ten Under Secretaries considered for promotion to Deputy Secretary, both
 * Class-I, with six year-wise CR columns (2012–2017).
 *
 * These run against `RuleSet::fromConfig()` — the *shipped* configuration, not
 * a fixture — so they prove the values the application will actually deploy
 * with reproduce the customer's own document. If a notification later changes
 * a threshold, these tests fail, which is the intended alarm: the golden cases
 * describe the 2026 rule set, and changing it must be a deliberate act with
 * `rule_version` bumped alongside.
 *
 * Slide 18 leaves MARK and REMARK blank, so the expectations below are derived
 * from the printed rules (requirements §5.1–§5.4) applied to the printed
 * grades — the arithmetic is what is being pinned, against data the customer
 * supplied.
 *
 * Where a row lists fewer grades than the six year columns, the slide gives no
 * alignment. Those rows are marked `inferred` and their years are read as the
 * most recent ones; they are asserted separately so an alignment correction
 * never masks a genuine arithmetic regression.
 */
class GoldenCaseTest extends TestCase
{
    private ScoringService $scoring;

    private EligibilityService $eligibility;

    private RuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scoring = new ScoringService;
        $this->eligibility = new EligibilityService;
        $this->rules = RuleSet::fromConfig();
    }

    /**
     * @param  array<int, string>  $crGrades
     */
    private function verdictFor(array $crGrades, bool $reserved = false, ?int $tribalYears = null)
    {
        $officer = new OfficerRecord(
            name: 'US',
            crGrades: $crGrades,
            tribalAreaServiceYears: $tribalYears,
            isReservedCategory: $reserved,
        );

        $score = $this->scoring->score($officer, $this->rules);

        return [$score, $this->eligibility->assess($officer, $score, ServiceClass::I, ServiceClass::I, $this->rules)];
    }

    public function test_the_shipped_configuration_matches_the_ppt_rule_set(): void
    {
        // C4: क+=4, क=3, ख=2, ग=1, घ=0.
        $this->assertSame(['क+' => 4, 'क' => 3, 'ख' => 2, 'ग' => 1, 'घ' => 0], $this->rules->crGradeMarks);
        // §5.4 thresholds.
        $this->assertSame(15, $this->rules->qualifyingScoreFor(ServiceClass::I, ServiceClass::I));
        $this->assertSame(13, $this->rules->qualifyingScoreFor(ServiceClass::II, ServiceClass::I));
        $this->assertSame(11, $this->rules->qualifyingScoreFor(ServiceClass::II, ServiceClass::II));
        $this->assertSame(10, $this->rules->qualifyingScoreFor(ServiceClass::III, ServiceClass::III));
        // §5.1–§5.3.
        $this->assertSame(5, $this->rules->scoringYears);
        $this->assertSame(1, $this->rules->tribalAreaBonus);
        $this->assertSame('घ', $this->rules->disqualifyingGrade);
        $this->assertSame('ग', $this->rules->lastYearForbiddenGradeFor(ServiceClass::I, ServiceClass::I));
        // Q5/Q6 remain unruled, so relaxation must ship disabled.
        $this->assertFalse($this->rules->reservedRelaxationEnabled);
    }

    /**
     * The seven S18 rows whose six printed grades align unambiguously with the
     * six year columns.
     *
     * @return array<string, array{0: array<int, string>, 1: int, 2: bool}>
     */
    public static function unambiguousRows(): array
    {
        $years = static fn (string ...$g): array => array_combine([2012, 2013, 2014, 2015, 2016, 2017], $g);

        return [
            // S18 row 1 — US1. Last five: क(3) ख(2) क(3) क(3) क+(4) = 15.
            'US1 scores exactly the Class-I threshold' => [$years('क+', 'क', 'ख', 'क', 'क', 'क+'), 15, true],

            // Row 2 — US2, all क. Note "PUNISHMENT" is an officer-entered Note,
            // not an engine gate, so it does not affect this verdict.
            'US2 clears on five straight क' => [$years('क', 'क', 'क', 'क', 'क', 'क'), 15, true],

            // Row 3 — US3. Last five: ख(2) क(3) क(3) क(3) क(3) = 14, one short.
            'US3 falls one mark short' => [$years('क', 'ख', 'क', 'क', 'क', 'क'), 14, false],

            // Row 5 — US5. Last five total 15, but 2014 is घ: the hard gate
            // beats the threshold. The single most valuable case here.
            'US5 meets the score but is failed by a घ grade' => [$years('ख', 'क+', 'घ', 'क', 'क+', 'क+'), 15, false],

            // Row 7 — US7, all क.
            'US7 clears on five straight क' => [$years('क', 'क', 'क', 'क', 'क', 'क'), 15, true],

            // Row 8 — US8. Last five all क+ = 20, the Super Seat case (SS
            // handling itself is Phase 2; eligibility is settled here).
            'US8 scores the maximum 20' => [$years('क', 'क+', 'क+', 'क+', 'क+', 'क+'), 20, true],

            // Row 10 — US10. Last five: ख(2) क(3) क(3) क(3) क+(4) = 15.
            'US10 scores exactly the threshold' => [$years('ख', 'ख', 'क', 'क', 'क', 'क+'), 15, true],
        ];
    }

    /**
     * @param  array<int, string>  $crGrades
     */
    #[DataProvider('unambiguousRows')]
    public function test_s18_worked_example_rows(array $crGrades, int $expectedScore, bool $expectedEligible): void
    {
        [$score, $verdict] = $this->verdictFor($crGrades);

        $this->assertSame($expectedScore, $score->total, 'Score mismatch against the PPT worked example.');
        $this->assertSame($expectedEligible, $verdict->eligible, 'Eligibility mismatch against the PPT worked example.');
        $this->assertSame([2017, 2016, 2015, 2014, 2013], $score->yearsUsed, 'The 2012 column must not be scored.');
    }

    public function test_s18_row_9_is_failed_by_a_final_year_of_ga(): void
    {
        // Row 9 — US9: क+ क क+ क ग. Five grades against six columns, so the
        // alignment is inferred as the five most recent years. Total is 15,
        // and the officer is still refused because §5.3 forbids a final-year
        // ग for a Class-I to Class-I promotion.
        [$score, $verdict] = $this->verdictFor([2013 => 'क+', 2014 => 'क', 2015 => 'क+', 2016 => 'क', 2017 => 'ग']);

        $this->assertSame(15, $score->total);
        $this->assertFalse($verdict->eligible);
        $this->assertStringContainsString('Most recent CR (2017) is ग', implode(' ', $verdict->failureReasons));
    }

    public function test_s18_row_4_clears_on_five_recorded_years(): void
    {
        // Row 4 — US4: क+ क ख क क (inferred alignment). Note "C.CASE" is an
        // officer-entered Note, not an engine gate.
        [$score, $verdict] = $this->verdictFor([2013 => 'क+', 2014 => 'क', 2015 => 'ख', 2016 => 'क', 2017 => 'क']);

        $this->assertSame(15, $score->total);
        $this->assertTrue($verdict->eligible);
        $this->assertFalse($score->hasIncompleteWindow());
    }

    public function test_s18_row_6_has_an_incomplete_cr_window(): void
    {
        // Row 6 — US6 lists only four grades: क+ क क क+ (inferred alignment).
        // Four graded years against a five-year window, so the officer is both
        // short of the threshold and flagged as incomplete — the situation a
        // Circulation DPC exists for. Whether promotion is *held* rather than
        // refused is M9's ruling; Phase 1 only surfaces it.
        [$score, $verdict] = $this->verdictFor([2014 => 'क+', 2015 => 'क', 2016 => 'क', 2017 => 'क+']);

        $this->assertSame(14, $score->total);
        $this->assertTrue($score->hasIncompleteWindow());
        $this->assertTrue($verdict->incompleteCrWindow);
        $this->assertFalse($verdict->eligible);
    }

    public function test_the_full_s18_chart_produces_a_stable_set_of_eligible_officers(): void
    {
        // The whole slide in one assertion, so a rule change that shifts any
        // officer across the line is caught even if no single row test moves.
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

        $eligible = [];
        foreach ($chart as $name => $grades) {
            [, $verdict] = $this->verdictFor($grades);
            if ($verdict->eligible) {
                $eligible[] = $name;
            }
        }

        $this->assertSame(['US1', 'US2', 'US4', 'US7', 'US8', 'US10'], $eligible);
    }

    public function test_no_officer_is_relaxed_while_the_rule_is_unruled(): void
    {
        // A reserved-category officer on 13 marks: exactly the case Q5/Q6
        // would decide. Until they are ruled, the engine must refuse and flag,
        // never quietly promote.
        [$score, $verdict] = $this->verdictFor(
            [2013 => 'ख', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क'],
            reserved: true,
        );

        $this->assertSame(13, $score->total);
        $this->assertFalse($verdict->eligible);
        $this->assertTrue($verdict->needsBusinessDecision());
    }

    public function test_a_calculation_can_be_explained_from_its_stored_output(): void
    {
        // Architect Decision 1 + directive: a run must persist the years used,
        // the rule version, and the configuration snapshot. Phase 1 produces
        // all of it in serialisable form; Phase 3 writes it.
        [$score, $verdict] = $this->verdictFor(
            [2012 => 'क+', 2013 => 'क', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क+'],
            tribalYears: 2,
        );

        $stored = [
            'rule_version' => $this->rules->version,
            'configuration_snapshot' => $this->rules->toArray(),
            'score' => $score->toArray(),
            'verdict' => $verdict->toArray(),
        ];

        $this->assertSame([2017, 2016, 2015, 2014, 2013], $stored['score']['years_used']);
        $this->assertSame(15, $stored['score']['base_total']);
        $this->assertSame(1, $stored['score']['tribal_area_bonus']);
        $this->assertSame(16, $stored['score']['total']);
        $this->assertSame(15, $stored['verdict']['required_score']);
        $this->assertTrue($stored['verdict']['eligible']);
        $this->assertNotSame('', $stored['rule_version']);
        $this->assertSame(5, $stored['configuration_snapshot']['scoring_years']);

        // The whole record must survive a round trip to storage.
        $this->assertIsString(json_encode($stored, JSON_THROW_ON_ERROR));
    }
}
