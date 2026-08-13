<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Services\EligibilityService;
use App\Domain\Eligibility\Services\ScoringService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Enums\ServiceClass;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsRuleSets;

/**
 * Eligibility gates and qualifying scores (PPT C4–C6, requirements
 * §5.3–§5.4) — M5 Phase 1.
 */
class EligibilityServiceTest extends TestCase
{
    use BuildsRuleSets;

    private ScoringService $scoring;

    private EligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new ScoringService;
        $this->eligibility = new EligibilityService;
    }

    private function assess(
        array $crGrades,
        ServiceClass $from = ServiceClass::I,
        ServiceClass $to = ServiceClass::I,
        bool $reserved = false,
        ?int $tribalYears = null,
        array $ruleOverrides = [],
    ) {
        $officer = new OfficerRecord(
            name: 'Test Officer',
            crGrades: $crGrades,
            tribalAreaServiceYears: $tribalYears,
            isReservedCategory: $reserved,
        );
        $rules = $this->ruleSet($ruleOverrides);

        return $this->eligibility->assess($officer, $this->scoring->score($officer, $rules), $from, $to, $rules);
    }

    private function fiveYears(string ...$grades): array
    {
        return array_combine([2013, 2014, 2015, 2016, 2017], $grades);
    }

    // ------------------------------------------------------------- §5.3 gates

    public function test_a_gh_grade_anywhere_in_the_window_fails_outright(): void
    {
        // The gate is absolute: this officer's total (15) meets the Class-I
        // threshold, and they are still not eligible.
        $verdict = $this->assess($this->fiveYears('क+', 'घ', 'क', 'क+', 'क+'));

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RemarkCode::NotEligible, $verdict->remark);
        $this->assertSame(15, $verdict->achievedScore);
        $this->assertStringContainsString('घ', $verdict->failureReasons[0]);
        $this->assertStringContainsString('2014', $verdict->failureReasons[0]);
    }

    public function test_a_gh_grade_outside_the_scoring_window_does_not_disqualify(): void
    {
        // 2012 is the sixth column and is not scored, so it cannot fail them.
        $verdict = $this->assess([
            2012 => 'घ', 2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क',
        ]);

        $this->assertTrue($verdict->eligible);
    }

    public function test_class_i_to_i_rejects_a_final_year_of_ga(): void
    {
        $verdict = $this->assess($this->fiveYears('क+', 'क', 'क+', 'क+', 'ग'));

        $this->assertFalse($verdict->eligible);
        $this->assertStringContainsString('Most recent CR (2017) is ग', $verdict->failureReasons[0]);
    }

    public function test_the_final_year_gate_applies_only_to_the_configured_class_pair(): void
    {
        // Same grades, Class-II to Class-II: no such gate, and the qualifying
        // score is 11, so this officer clears.
        $verdict = $this->assess($this->fiveYears('क+', 'क', 'क+', 'क+', 'ग'), ServiceClass::II, ServiceClass::II);

        $this->assertTrue($verdict->eligible);
        $this->assertSame(11, $verdict->requiredScore);
    }

    public function test_a_ga_in_an_earlier_year_is_allowed_for_class_i(): void
    {
        // The gate is about the *last* year only.
        $verdict = $this->assess($this->fiveYears('ग', 'क+', 'क+', 'क+', 'क+'));

        $this->assertTrue($verdict->eligible);
        $this->assertSame(17, $verdict->achievedScore);
    }

    public function test_every_failed_gate_is_reported_not_just_the_first(): void
    {
        // An officer refused promotion is entitled to all the grounds.
        $verdict = $this->assess($this->fiveYears('घ', 'ख', 'ख', 'ख', 'ग'));

        $this->assertFalse($verdict->eligible);
        $this->assertCount(3, $verdict->failureReasons);
    }

    // -------------------------------------------------------- §5.4 thresholds

    public function test_qualifying_score_varies_by_class_pair(): void
    {
        $grades = $this->fiveYears('ख', 'ख', 'ख', 'ख', 'ख'); // 10 marks

        $this->assertSame(15, $this->assess($grades, ServiceClass::I, ServiceClass::I)->requiredScore);
        $this->assertSame(13, $this->assess($grades, ServiceClass::II, ServiceClass::I)->requiredScore);
        $this->assertSame(11, $this->assess($grades, ServiceClass::II, ServiceClass::II)->requiredScore);
        // PPT "शेष" — all other cases.
        $this->assertSame(10, $this->assess($grades, ServiceClass::III, ServiceClass::III)->requiredScore);
    }

    public function test_the_threshold_is_inclusive(): void
    {
        // Exactly 15 for Class-I to Class-I must pass, not fail.
        $verdict = $this->assess($this->fiveYears('क', 'ख', 'क', 'क', 'क+'));

        $this->assertSame(15, $verdict->achievedScore);
        $this->assertTrue($verdict->eligible);
    }

    public function test_one_mark_short_fails(): void
    {
        $verdict = $this->assess($this->fiveYears('ख', 'क', 'क', 'क', 'क'));

        $this->assertSame(14, $verdict->achievedScore);
        $this->assertFalse($verdict->eligible);
        $this->assertStringContainsString('requires 15', $verdict->failureReasons[0]);
    }

    public function test_the_tribal_bonus_can_carry_an_officer_over_the_threshold(): void
    {
        $without = $this->assess($this->fiveYears('ख', 'क', 'क', 'क', 'क'));
        $with = $this->assess($this->fiveYears('ख', 'क', 'क', 'क', 'क'), tribalYears: 3);

        $this->assertFalse($without->eligible);
        $this->assertTrue($with->eligible);
        $this->assertSame(15, $with->achievedScore);
    }

    // ------------------------------------ Q5/Q6 — waiting for business decision

    public function test_a_reserved_officer_below_the_threshold_is_flagged_not_relaxed(): void
    {
        // Architect ruling: do not guess. The printed rule stands (not
        // eligible), and the case is flagged for a business decision rather
        // than being silently resolved either way.
        $verdict = $this->assess($this->fiveYears('ख', 'ख', 'क', 'क', 'क'), reserved: true);

        $this->assertSame(13, $verdict->achievedScore);
        $this->assertFalse($verdict->eligible);
        $this->assertTrue($verdict->needsBusinessDecision());
        $this->assertStringContainsString('awaiting a business decision', implode(' ', $verdict->failureReasons));
    }

    public function test_an_unreserved_officer_is_never_flagged_for_relaxation(): void
    {
        $verdict = $this->assess($this->fiveYears('ख', 'ख', 'क', 'क', 'क'), reserved: false);

        $this->assertFalse($verdict->eligible);
        $this->assertFalse($verdict->needsBusinessDecision());
    }

    public function test_a_reserved_officer_below_the_relaxed_floor_is_not_flagged(): void
    {
        // Scoring under the floor means the relaxation could not have helped,
        // so there is no decision pending for this officer.
        $verdict = $this->assess($this->fiveYears('ख', 'ख', 'ख', 'ग', 'ग'), reserved: true);

        $this->assertSame(8, $verdict->achievedScore);
        $this->assertFalse($verdict->needsBusinessDecision());
    }

    public function test_a_reserved_officer_failing_a_hard_gate_is_not_flagged_for_relaxation(): void
    {
        // Relaxation is about the score. It cannot rescue a गह gate failure,
        // so flagging it would misrepresent what a business decision could change.
        $verdict = $this->assess($this->fiveYears('घ', 'क', 'क', 'क', 'क+'), reserved: true);

        $this->assertFalse($verdict->eligible);
        $this->assertFalse($verdict->needsBusinessDecision());
    }

    public function test_an_eligible_reserved_officer_is_not_flagged(): void
    {
        $verdict = $this->assess($this->fiveYears('क', 'क', 'क', 'क', 'क+'), reserved: true);

        $this->assertTrue($verdict->eligible);
        $this->assertFalse($verdict->needsBusinessDecision());
    }

    // ------------------------------------------------------ incomplete records

    public function test_an_incomplete_cr_window_is_surfaced_on_the_verdict(): void
    {
        // Not decided here: an officer missing CRs is what a Circulation DPC
        // handles (promotion held, not refused), which is M9's ruling to make.
        $verdict = $this->assess([2016 => 'क+', 2017 => 'क+']);

        $this->assertTrue($verdict->incompleteCrWindow);
        $this->assertFalse($verdict->eligible);
    }

    public function test_an_eligible_officer_has_no_failure_reasons(): void
    {
        $verdict = $this->assess($this->fiveYears('क+', 'क+', 'क+', 'क+', 'क+'));

        $this->assertTrue($verdict->eligible);
        $this->assertSame(RemarkCode::Eligible, $verdict->remark);
        $this->assertSame([], $verdict->failureReasons);
        $this->assertSame(20, $verdict->achievedScore);
    }
}
