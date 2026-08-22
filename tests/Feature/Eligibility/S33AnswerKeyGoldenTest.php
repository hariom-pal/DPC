<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\EligibilityService;
use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\Services\SuperSeatService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Enums\ServiceClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The customer's own answer key — PPT slide 33 (M5, added during Phase 4).
 *
 * Slide 33 walks the same ten Under Secretaries through four progressive
 * tables, and unlike slide 18 the later three have **MARK and REMARK filled
 * in**. That makes it the only place in either deck where CGPSC states the
 * expected output of the engine, rather than only its input.
 *
 * Earlier reviews recorded that no worked MARK/REMARK existed. That was wrong:
 * slide 18 leaves them blank, but slide 33 does not. These tests exist so the
 * engine is measured against the customer's stated answers.
 *
 * Result: **nine of the ten marks match exactly**, including three rows that
 * independently confirm decisions taken earlier on other grounds. Two
 * eligibility verdicts diverge, both for the same documented reason — see
 * `punishmentRows()` below and `docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md`.
 *
 * CR columns on the slide run 2012, 2013, 2014, 2015, "1016" (a typo for
 * 2016), 2017. A blank cell is a genuinely missing CR, not a zero.
 */
class S33AnswerKeyGoldenTest extends TestCase
{
    private ScoringService $scoring;

    private EligibilityService $eligibility;

    private SuperSeatService $superSeat;

    private RuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scoring = new ScoringService;
        $this->eligibility = new EligibilityService;
        $this->superSeat = new SuperSeatService;
        $this->rules = RuleSet::fromConfig();
    }

    /**
     * @param  list<string>  $grades  2012, 2013, 2014, 2015, 2016, 2017 — '' is a blank cell
     * @return array<int, string>
     */
    private static function years(array $grades): array
    {
        $years = [2012, 2013, 2014, 2015, 2016, 2017];
        $map = [];

        foreach ($years as $index => $year) {
            if (($grades[$index] ?? '') !== '') {
                $map[$year] = $grades[$index];
            }
        }

        return $map;
    }

    private function assess(array $crGrades, bool $reserved = false, ?int $tribalYears = null): array
    {
        $officer = new OfficerRecord(
            name: 'US',
            crGrades: $crGrades,
            tribalAreaServiceYears: $tribalYears,
            isReservedCategory: $reserved,
        );

        $score = $this->scoring->score($officer, $this->rules);

        return [
            $score,
            $this->eligibility->assess($officer, $score, ServiceClass::I, ServiceClass::I, $this->rules),
            $this->superSeat->assess($officer, $score, $this->rules),
        ];
    }

    /**
     * The eight rows where the engine reproduces slide 33's stated MARK.
     *
     * @return array<string, array{0: array<int, string>, 1: int, 2: bool, 3: bool}>
     */
    public static function answerKeyRows(): array
    {
        return [
            // MARK 15, REMARK EL -> UR. Straightforward.
            'US1 — 15, eligible' => [self::years(['क+', 'क', 'ख', 'क', 'क', 'क+']), 15, true, false],

            // MARK 14, REMARK NE in table 2 — one mark short, before the
            // tribal-area entry appears. Covered with the bonus below.
            'US3 — 14 before the tribal entry' => [self::years(['क', 'ख', 'क', 'क', 'क', 'क']), 14, false, false],

            // MARK 15, REMARK NE. Scores the threshold and is still refused by
            // the घ gate — the case a total-only engine would get wrong.
            'US5 — 15 but failed by घ' => [self::years(['ख', 'क+', 'घ', 'क', 'क+', 'क+']), 15, false, false],

            // MARK 15, REMARK EL.
            'US7 — 15, eligible' => [self::years(['क', 'क', 'क', 'क', 'क', 'क']), 15, true, false],

            // MARK 20. Super Seat.
            'US8 — 20, Super Seat' => [self::years(['क', 'क+', 'क+', 'क+', 'क+', 'क+']), 20, true, true],

            // MARK 15, REMARK NE. Refused by the Class-I final-year ग gate.
            'US9 — 15 but failed by a final-year ग' => [self::years(['क+', 'क', '', 'क+', 'क', 'ग']), 15, false, false],
        ];
    }

    /**
     * @param  array<int, string>  $crGrades
     */
    #[DataProvider('answerKeyRows')]
    public function test_s33_answer_key_rows(array $crGrades, int $expectedMark, bool $expectedEligible, bool $expectedSuperSeat): void
    {
        [$score, $verdict, $superSeat] = $this->assess($crGrades);

        $this->assertSame($expectedMark, $score->total, "MARK disagrees with slide 33's stated value.");
        $this->assertSame($expectedEligible, $verdict->eligible, "REMARK disagrees with slide 33's stated verdict.");
        $this->assertSame($expectedSuperSeat, $superSeat->qualifies);
    }

    public function test_us4_confirms_the_five_most_recent_available_years_rule(): void
    {
        // US4's 2014 cell is blank, and slide 33 still states MARK 15. That is
        // only reachable if the window reaches back to 2012 to find a fifth
        // *graded* year: 2017(3) + 2016(3) + 2015(2) + 2013(3) + 2012(4) = 15.
        //
        // Independent confirmation of architect Decision 1 — "five most recent
        // available CR years" — from the customer's own arithmetic.
        [$score, $verdict] = $this->assess(self::years(['क+', 'क', '', 'ख', 'क', 'क']));

        $this->assertSame(15, $score->total);
        $this->assertSame([2017, 2016, 2015, 2013, 2012], $score->yearsUsed);
        $this->assertFalse($score->hasIncompleteWindow());
        $this->assertTrue($verdict->eligible);
    }

    public function test_us3_confirms_the_tribal_area_bonus_changes_the_outcome(): void
    {
        // Slide 33 shows US3 twice: 14 / NE in table 2, then 15 / ST_W in
        // table 3 once "TRIBAL 2021" is recorded in the Note column. The
        // customer's own worked example of §5.2 flipping a verdict.
        $grades = self::years(['क', 'ख', 'क', 'क', 'क', 'क']);

        [$without, $verdictWithout] = $this->assess($grades, reserved: true);
        [$with, $verdictWith] = $this->assess($grades, reserved: true, tribalYears: 1);

        $this->assertSame(14, $without->total);
        $this->assertFalse($verdictWithout->eligible);

        $this->assertSame(15, $with->total);
        $this->assertSame(1, $with->tribalAreaBonus);
        $this->assertTrue($verdictWith->eligible);
    }

    public function test_us6_has_an_incomplete_window_which_slide_33_marks_as_circulation(): void
    {
        // Slide 33 gives US6 MARK "-" (no mark at all) and REMARK CIR: with
        // only four graded CR years, it withholds the score rather than
        // computing a partial one.
        //
        // The engine computes 14 and *flags* the incomplete window. Both agree
        // the officer is not simply eligible; they differ on whether a partial
        // score is published. Withholding is a circulation-handling decision
        // and belongs to M9 (Q12), so it is recorded here rather than assumed.
        [$score, $verdict] = $this->assess(self::years(['क+', '', '', 'क', 'क', 'क+']));

        $this->assertTrue($score->hasIncompleteWindow());
        $this->assertSame(4, $score->gradedYearCount());
        $this->assertTrue($verdict->incompleteCrWindow);
        $this->assertFalse($verdict->eligible);
    }

    /**
     * Rows where the engine and slide 33 disagree.
     *
     * Both are officers whose Note column reads PUNISHMENT. They score 15 —
     * which the engine and the slide agree on — but slide 33 marks them **NE**
     * while the engine marks them eligible.
     *
     * The engine treats Note as officer-entered commentary, not a gate, and
     * requirements §5.3 lists only two hard gates, neither mentioning
     * PUNISHMENT. Slide 33 is the only evidence for a third gate, and it is
     * consistent across both affected rows.
     *
     * **Not changed unilaterally.** The scoring and eligibility rules are
     * approved and about to be frozen, and adding a disqualification that
     * refuses officers is precisely the kind of change that must not be made
     * on an engineer's reading of a slide. Raised in
     * `docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md`; these assertions pin *current* behaviour so
     * a ruling has one obvious place to land.
     *
     * @return array<string, array{0: array<int, string>}>
     */
    public static function punishmentRows(): array
    {
        return [
            'US2 — PUNISHMENT, slide says NE' => [self::years(['क', 'क', 'क', 'क', 'क', 'क'])],
            'US10 — PUNISHMENT, slide says NE' => [self::years(['ख', 'ख', 'क', 'क', 'क', 'क+'])],
        ];
    }

    /**
     * @param  array<int, string>  $crGrades
     */
    #[DataProvider('punishmentRows')]
    public function test_punishment_rows_score_as_the_slide_says_but_are_not_yet_disqualified(array $crGrades): void
    {
        [$score, $verdict] = $this->assess($crGrades);

        // The MARK matches slide 33 exactly.
        $this->assertSame(15, $score->total);

        // The REMARK does not: the slide says NE, the engine says eligible,
        // because no PUNISHMENT gate has been ruled. Awaiting architect
        // decision — see docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md.
        $this->assertTrue(
            $verdict->eligible,
            'Current behaviour: no PUNISHMENT gate exists. Slide 33 says NE — awaiting an architect ruling.',
        );
    }

    public function test_nine_of_the_ten_stated_marks_are_reproduced(): void
    {
        // The whole answer key in one assertion. US6 is the only MARK the
        // engine does not reproduce, because slide 33 withholds a mark for an
        // incomplete CR window rather than computing a partial one.
        $chart = [
            'US1' => [['क+', 'क', 'ख', 'क', 'क', 'क+'], 15, null],
            'US2' => [['क', 'क', 'क', 'क', 'क', 'क'], 15, null],
            'US3' => [['क', 'ख', 'क', 'क', 'क', 'क'], 15, 1],   // with TRIBAL 2021
            'US4' => [['क+', 'क', '', 'ख', 'क', 'क'], 15, null],
            'US5' => [['ख', 'क+', 'घ', 'क', 'क+', 'क+'], 15, null],
            'US7' => [['क', 'क', 'क', 'क', 'क', 'क'], 15, null],
            'US8' => [['क', 'क+', 'क+', 'क+', 'क+', 'क+'], 20, null],
            'US9' => [['क+', 'क', '', 'क+', 'क', 'ग'], 15, null],
            'US10' => [['ख', 'ख', 'क', 'क', 'क', 'क+'], 15, null],
        ];

        $computed = [];
        foreach ($chart as $name => [$grades, $statedMark, $tribal]) {
            [$score] = $this->assess(self::years($grades), tribalYears: $tribal);
            $computed[$name] = $score->total;
        }

        $this->assertSame(
            array_map(static fn (array $row): int => $row[1], $chart),
            $computed,
            'The engine must reproduce every MARK slide 33 states.',
        );
    }
}
