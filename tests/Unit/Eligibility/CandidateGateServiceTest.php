<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Services\CandidateGateService;
use App\Domain\Eligibility\ValueObjects\CandidateGateVerdict;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Eligibility\MeritGateTest;

/**
 * Items 12 to 20 and 24 of the change register of 2026-08-27 — the gates that run
 * before the marking formula.
 *
 * A unit test with no database, matching the service: everything arrives on an
 * `OfficerRecord`. That is also what keeps these assertions about *business rules*
 * rather than about the chart's plumbing, which
 * {@see MeritGateTest} covers end to end.
 *
 * The five CR years used throughout are 2013–2017, the same window the golden tests
 * use, so a reader comparing the two is comparing like with like.
 */
final class CandidateGateServiceTest extends TestCase
{
    private const YEARS = [2013, 2014, 2015, 2016, 2017];

    private CandidateGateService $gates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gates = new CandidateGateService;
    }

    // =====================================================================
    // Item 13 — Property Return
    // =====================================================================

    /**
     * > *Validate the last five continuous years. If even one year is missing,
     * > Candidate Status = Rejected.*
     *
     * And the reason carries the register's own words, because that is the sentence
     * the officer will be shown and the one the Commission approved.
     */
    public function test_one_missing_property_year_rejects_the_candidate(): void
    {
        $verdict = $this->assess($this->officer(propertyYears: [2013, 2014, 2016, 2017]));

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
        $this->assertStringContainsString(
            'Property Return not available for mandatory 5 continuous years',
            implode(' ', $verdict->reasons),
        );
        $this->assertStringContainsString('2015', implode(' ', $verdict->reasons));
    }

    public function test_a_complete_property_window_does_not_reject(): void
    {
        $this->assertNull($this->assess($this->officer())->status);
    }

    /**
     * No Property Return at all is missing all five, not missing none.
     *
     * The plain reading, and the one that refuses rather than waves through: an empty
     * set of records is exactly the case the rule exists to catch.
     */
    public function test_no_property_return_at_all_is_a_rejection(): void
    {
        $verdict = $this->assess($this->officer(propertyYears: []));

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
    }

    /**
     * The window is anchored on the officer's own most recent record, not on a
     * calendar.
     *
     * A calculation re-run next year has to give the answer it gave when the
     * Commission acted (BD-37). An officer whose five years are 2009–2013 has a
     * complete window even though those years are old — the *continuity* is the rule,
     * not the recency.
     */
    public function test_the_window_follows_the_officers_own_records(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2009, 2010, 2011, 2012, 2013],
            propertyYears: [2009, 2010, 2011, 2012, 2013],
            loyaltyYears: [2009, 2010, 2011, 2012, 2013],
        ));

        $this->assertNull($verdict->status);
    }

    /**
     * A rejection beats everything else, and beats it first.
     *
     * Item 20 lists the automatic rejections; a candidate who is out on one of them
     * is out, and computing a merit score for them would put a mark beside a
     * rejection where it reads as a near miss.
     */
    public function test_a_hierarchy_failure_rejects_and_is_reported_beside_the_property_ground(): void
    {
        $verdict = $this->assess(
            $this->officer(propertyYears: []),
            hierarchyFailures: ['Promotion is from Class-I into Class-II, which is a demotion.'],
        );

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
        $this->assertCount(2, $verdict->reasons, 'both grounds are owed to the officer, not just the first');
    }

    // =====================================================================
    // Item 12 — the recruitment rule
    // =====================================================================

    /**
     * **BD-39 — an unmatched qualification is reported, not applied.**
     *
     * Item 12 requires the qualification to be validated against the Recruitment
     * Rule but does not say how the comparison is made, and the two sides are not
     * comparable as they stand: the rule names a *level* from the Qualification
     * Master ("Post-Graduate"), the chart carries the *degree* an operator typed
     * ("M.A., B.Ed."). Nothing in code knows those describe the same officer.
     *
     * So by default the finding lands on the run's pending decisions and the status
     * is left alone. That is the only defensible direction: enforcing it on today's
     * data refuses officers who plainly hold the qualification, and a promotion
     * wrongly refused is the portal asserting something untrue about a named person.
     */
    public function test_an_unmatched_qualification_is_advisory_by_default(): void
    {
        $verdict = $this->assess($this->officer(
            qualification: 'M.A., B.Ed.',
            requiredQualifications: ['Post-Graduate'],
        ));

        $this->assertNull($verdict->status, 'the status must be left to the score');
        $this->assertSame([], $verdict->reasons);
        $this->assertCount(1, $verdict->advisories);
        $this->assertStringContainsString('Qualification not satisfied', implode(' ', $verdict->advisories));
    }

    /**
     * And it becomes a refusal the day the Commission rules — a setting, not a code
     * change.
     */
    public function test_the_qualification_gate_refuses_when_it_is_enforced(): void
    {
        $verdict = $this->assess(
            $this->officer(qualification: 'B.Com', requiredQualifications: ['B.E.', 'B.Tech']),
            enforceQualification: true,
        );

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('Qualification not satisfied', implode(' ', $verdict->reasons));
        $this->assertSame([], $verdict->advisories, 'an applied finding is not also an advisory');
    }

    /**
     * A satisfied qualification produces neither a failure nor an advisory.
     *
     * Pinned because an advisory raised on every officer would be noise, and noise on
     * a pending-decisions list is how a real pending decision gets missed.
     */
    public function test_a_satisfied_qualification_raises_nothing(): void
    {
        $verdict = $this->assess($this->officer(
            qualification: 'B.Tech',
            requiredQualifications: ['B.Tech'],
        ));

        $this->assertNull($verdict->status);
        $this->assertSame([], $verdict->advisories);
    }

    /**
     * Item 11 of the register of 2026-08-26 made qualifications multi-valued, and a
     * list of degrees is a list of **alternatives**.
     *
     * Requiring all of them would refuse every real candidate — nobody holds every
     * degree a Schedule-IV entry names.
     */
    public function test_any_one_of_the_accepted_qualifications_satisfies_the_rule(): void
    {
        $verdict = $this->assess($this->officer(
            qualification: 'B.Tech',
            requiredQualifications: ['B.E.', 'B.Tech', 'M.C.A.'],
        ));

        $this->assertNull($verdict->status);
    }

    /**
     * The chart's qualification is free text an operator typed, so it is matched as a
     * normalised substring.
     *
     * `M.A., B.Ed.` satisfies a requirement recorded as `B.Ed.` — and it does not do
     * so by being equal to it.
     */
    public function test_a_compound_qualification_satisfies_a_requirement_it_contains(): void
    {
        $verdict = $this->assess($this->officer(
            qualification: 'M.A., B.Ed.',
            requiredQualifications: ['B.Ed.'],
        ));

        $this->assertNull($verdict->status);
    }

    public function test_short_qualifying_service_is_a_rule_failure(): void
    {
        $verdict = $this->assess($this->officer(
            serviceMonths: 36,
            requiredExperienceMonths: 60,
        ));

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('Promotion experience not completed', implode(' ', $verdict->reasons));
    }

    public function test_an_incomplete_promotion_duration_is_a_rule_failure(): void
    {
        $verdict = $this->assess($this->officer(
            serviceMonths: 24,
            requiredPromotionDurationMonths: 84,
        ));

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('Promotion duration not completed', implode(' ', $verdict->reasons));
    }

    /**
     * A requirement published in **months** is checked in months.
     *
     * `min_experience_unit` exists on the master because *"rounding those up to a
     * year changes who is eligible"*. An officer with 20 months of qualifying service
     * satisfies an 18-month rule; measuring them in whole years would round them to 1
     * and refuse them. Pinned in both directions, because the round-down error only
     * shows on the passing case.
     */
    public function test_an_eighteen_month_rule_is_satisfied_by_twenty_months(): void
    {
        $this->assertNull($this->assess($this->officer(
            serviceMonths: 20,
            requiredExperienceMonths: 18,
        ))->status);

        $short = $this->assess($this->officer(
            serviceMonths: 17,
            requiredExperienceMonths: 18,
        ));

        $this->assertSame(RemarkCode::NotEligible, $short->status);
        $this->assertStringContainsString('18 month(s) required', implode(' ', $short->reasons));
    }

    /**
     * A whole-year requirement still reads as years.
     *
     * The comparison is in months; the *message* is not, because an officer told
     * "60 month(s) required, 36 month(s) served" has to do the arithmetic themselves.
     */
    public function test_a_whole_year_requirement_is_reported_in_years(): void
    {
        $verdict = $this->assess($this->officer(
            serviceMonths: 36,
            requiredExperienceMonths: 60,
        ));

        $this->assertStringContainsString('5 year(s) required', implode(' ', $verdict->reasons));
        $this->assertStringContainsString('3 year(s) served', implode(' ', $verdict->reasons));
    }

    /**
     * A requirement the rule does not impose is not an unmet requirement.
     *
     * A Schedule-IV entry that asks for nothing beyond holding the feeder post is
     * ordinary; treating its silence as a failure would fail every candidate under it.
     */
    public function test_a_rule_that_prescribes_nothing_fails_nobody(): void
    {
        $this->assertSame([], $this->gates->recruitmentRuleFailures($this->officer()));
    }

    /**
     * A missing posting date leaves the service gate unanswerable, and unanswerable
     * is not failed — the officer would otherwise pay for their department's data
     * entry.
     */
    public function test_an_unrecorded_service_length_skips_the_service_gates(): void
    {
        $verdict = $this->assess($this->officer(
            serviceMonths: null,
            requiredExperienceMonths: 60,
            requiredPromotionDurationMonths: 84,
        ));

        $this->assertNull($verdict->status);
    }

    /**
     * **BD-32.** Item 12 says a rule failure is *Not Eligible*; item 20 lists the same
     * conditions under *Automatically Reject*. The status is a setting rather than a
     * guess, so a ruling either way is a configuration change and not a code change.
     */
    public function test_the_rule_failure_status_is_the_one_it_is_given(): void
    {
        $verdict = $this->assess(
            $this->officer(qualification: 'B.Com', requiredQualifications: ['B.E.']),
            ruleFailureStatus: RemarkCode::Rejected,
            enforceQualification: true,
        );

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
    }

    // =====================================================================
    // Items 14, 15 and 19 — CIR
    // =====================================================================

    /**
     * > *Candidate shall continue in the DPC. Status = CIR.*
     *
     * A justified missing CR holds the promotion; it does not refuse it. That is why
     * CIR is a status of its own and not a flavour of Not Eligible.
     */
    public function test_a_justified_missing_cr_produces_cir(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2014, 2015, 2016, 2017],
            loyaltyYears: [2014, 2015, 2016, 2017],
            justifiedCrGaps: [2013 => 'CR 2013 — Record Not Available (ref. DOC-1)'],
        ));

        $this->assertSame(RemarkCode::Circulation, $verdict->status);
        $this->assertSame(['CR 2013 — Record Not Available (ref. DOC-1)'], $verdict->gapCitations);
    }

    /**
     * The same shortfall with nothing said about it is **not** CIR.
     *
     * Item 19 opens the CIR path only for a gap the officer has explained *and*
     * evidenced. A missing record nobody has accounted for is simply a missing
     * mandatory record.
     */
    public function test_an_unexplained_missing_cr_is_not_cir(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2014, 2015, 2016, 2017],
            loyaltyYears: [2014, 2015, 2016, 2017],
        ));

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('no supporting document', implode(' ', $verdict->reasons));
    }

    /**
     * Item 14: *"The five CRs need NOT be continuous."*
     *
     * Deliberately unlike the Property Return above, which must be. The difference is
     * the register's, so both are tested — an implementation that treated them alike
     * would be wrong whichever way it leaned.
     */
    public function test_the_five_crs_need_not_be_continuous(): void
    {
        $years = [2008, 2011, 2013, 2016, 2017];

        $verdict = $this->assess($this->officer(
            crYears: $years,
            propertyYears: self::YEARS,
            loyaltyYears: $years,
        ));

        $this->assertNull($verdict->status);
    }

    /**
     * Item 15: the Loyalty Certificate belongs to the **CR year**, so it is checked
     * against the years carrying a CR rather than against a calendar.
     */
    public function test_a_justified_missing_loyalty_certificate_produces_cir(): void
    {
        $verdict = $this->assess($this->officer(
            loyaltyYears: [2013, 2014, 2015, 2017],
            justifiedLoyaltyGaps: [2016 => 'Loyalty 2016 — Department Delay (ref. DOC-2)'],
        ));

        $this->assertSame(RemarkCode::Circulation, $verdict->status);
        $this->assertSame(['Loyalty 2016 — Department Delay (ref. DOC-2)'], $verdict->gapCitations);
    }

    public function test_an_unexplained_missing_loyalty_certificate_is_not_cir(): void
    {
        $verdict = $this->assess($this->officer(loyaltyYears: [2013, 2014, 2015, 2017]));

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('Loyalty Certificate for 2016', implode(' ', $verdict->reasons));
    }

    /**
     * Item 17 — and the reason none of this rejects a new appointee.
     *
     * > *If the employee has not completed five years of service, allow available
     * > records only. The candidate shall not be rejected only because five years of
     * > service has not yet been completed.*
     *
     * A two-year officer has two CRs, which is all there is to have. With the missing
     * years justified they land on CIR: not Rejected, and not Not Eligible. That is
     * not a special case in the code — it falls out of treating a justified gap as
     * CIR and never as a rejection.
     */
    public function test_a_newly_appointed_officer_is_held_rather_than_refused(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2016, 2017],
            propertyYears: [2013, 2014, 2015, 2016, 2017],
            loyaltyYears: [2016, 2017],
            justifiedCrGaps: [
                2013 => 'CR 2013 — Newly Appointed',
                2014 => 'CR 2014 — Newly Appointed',
                2015 => 'CR 2015 — Newly Appointed',
            ],
        ));

        $this->assertSame(RemarkCode::Circulation, $verdict->status);
        $this->assertNotSame(RemarkCode::Rejected, $verdict->status);
        $this->assertCount(3, $verdict->gapCitations);
    }

    /**
     * A newly appointed officer still owes their Property Returns for the years they
     * have served — item 17 relaxes the *records that cannot exist*, not the ones that
     * can.
     *
     * Pinned because the opposite reading is tempting and would let item 13 be
     * side-stepped by anyone recently promoted.
     */
    public function test_item_17_does_not_waive_the_property_return(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2016, 2017],
            propertyYears: [2016, 2017],
            loyaltyYears: [2016, 2017],
            justifiedCrGaps: [2013 => 'a', 2014 => 'b', 2015 => 'c'],
        ));

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
    }

    // =====================================================================
    // Order of the gates — the order *is* the business rule
    // =====================================================================

    /**
     * Rejection outranks a rule failure, which outranks CIR.
     *
     * An officer failing all three arrives at Rejected: they are out on the hardest
     * ground, and a status that said "held pending a document" would misdescribe
     * someone who is not in contention at all.
     */
    public function test_rejection_outranks_the_other_gates(): void
    {
        $verdict = $this->assess($this->officer(
            crYears: [2017],
            propertyYears: [],
            loyaltyYears: [],
            qualification: 'B.Com',
            requiredQualifications: ['B.E.'],
            justifiedCrGaps: [2013 => 'a'],
        ));

        $this->assertSame(RemarkCode::Rejected, $verdict->status);
    }

    /**
     * A rule failure outranks CIR: a candidate the recruitment rule excludes is not in
     * contention, so holding their promotion pending a document would hold something
     * that was never going to happen.
     */
    public function test_a_rule_failure_outranks_cir(): void
    {
        $verdict = $this->assess(
            $this->officer(
                crYears: [2014, 2015, 2016, 2017],
                loyaltyYears: [2014, 2015, 2016, 2017],
                qualification: 'B.Com',
                requiredQualifications: ['B.E.'],
                justifiedCrGaps: [2013 => 'a'],
            ),
            enforceQualification: true,
        );

        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
    }

    /**
     * A clean candidate is not awarded eligibility here — the *score* decides.
     *
     * `passed()` carries a null status precisely so this object can never make someone
     * look promotable who has not been scored.
     */
    public function test_a_clean_candidate_is_left_to_the_score(): void
    {
        $verdict = $this->assess($this->officer());

        $this->assertNull($verdict->status);
        $this->assertFalse($verdict->isDecided());
        $this->assertSame([], $verdict->reasons);
    }

    // =====================================================================

    /**
     * @param  list<string>  $hierarchyFailures
     */
    private function assess(
        OfficerRecord $officer,
        array $hierarchyFailures = [],
        RemarkCode $ruleFailureStatus = RemarkCode::NotEligible,
        bool $enforceQualification = false,
    ): CandidateGateVerdict {
        return $this->gates->assess(
            $officer, $hierarchyFailures, 5, 5, $ruleFailureStatus, $enforceQualification,
        );
    }

    /**
     * An officer who clears every gate, with each input overridable.
     *
     * Defaults matter here: a helper that defaulted to *missing* records would make
     * every test above pass for the wrong reason.
     *
     * @param  list<int>  $crYears
     * @param  list<int>  $propertyYears
     * @param  list<int>  $loyaltyYears
     * @param  array<int, string>  $justifiedCrGaps
     * @param  array<int, string>  $justifiedLoyaltyGaps
     * @param  list<string>  $requiredQualifications
     */
    private function officer(
        array $crYears = self::YEARS,
        ?array $propertyYears = null,
        ?array $loyaltyYears = null,
        array $justifiedCrGaps = [],
        array $justifiedLoyaltyGaps = [],
        ?string $qualification = 'B.A.',
        array $requiredQualifications = [],
        ?int $serviceMonths = 120,
        ?int $requiredExperienceMonths = null,
        ?int $requiredPromotionDurationMonths = null,
    ): OfficerRecord {
        /*
         | Property defaults to the full window, loyalty to the CR years.
         |
         | Not the same default, and the difference is item 15's: a Loyalty
         | Certificate belongs to a CR year, so an officer with four CRs owes four
         | certificates — but they owe five Property Returns regardless, because item
         | 13's window is continuous and independent of the CR chart. Defaulting
         | property to the CR years would make every short-CR test above reject on
         | the property ground instead of the one it is testing.
         */
        $propertyYears ??= self::YEARS;
        $loyaltyYears ??= $crYears;

        return new OfficerRecord(
            name: 'US1',
            crGrades: array_fill_keys($crYears, 'क'),
            propertyReturnYears: array_fill_keys($propertyYears, true),
            loyaltyByYear: array_fill_keys($loyaltyYears, true),
            justifiedCrGapYears: $justifiedCrGaps,
            justifiedLoyaltyGapYears: $justifiedLoyaltyGaps,
            qualification: $qualification,
            requiredQualifications: $requiredQualifications,
            serviceMonthsOnFeederPost: $serviceMonths,
            requiredExperienceMonths: $requiredExperienceMonths,
            requiredPromotionDurationMonths: $requiredPromotionDurationMonths,
        );
    }
}
