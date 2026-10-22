<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\ValueObjects\CandidateGateVerdict;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;

/**
 * The gates that run **before** the marking formula (change register of 2026-08-27,
 * items 12 to 20).
 *
 * ### Where this sits
 *
 * ```
 * PromotionHierarchyService   once per proposal  → item 11
 *          ↓
 * CandidateGateService        once per candidate → items 12–20   ← this class
 *          ↓
 * ScoringService              the PPT's formula  → item 21   (untouched)
 * EligibilityService          the PPT's minimum  → item 22   (untouched)
 * SuperSeatService            the PPT's rule     → item 23   (untouched)
 * ```
 *
 * **Nothing here touches the marking formula, the qualifying score or Super Seat.**
 * Items 21 to 23 say those are the PPT's and are not to be modified, so they are
 * reached only *after* these gates and are unchanged.
 *
 * ### The order of the gates is the business rule
 *
 * | Order | Gate | Status | Item |
 * |---|---|---|---|
 * | 1 | Property Return missing in the mandatory window | `Rejected` | 13, 20 |
 * | 2 | Recruitment rule — qualification, experience, promotion duration | `Not Eligible` * | 12 |
 * | 3 | A mandatory CR or Loyalty record missing but justified | `CIR` | 14, 15, 19 |
 * | 4 | nothing to say | *the score decides* | 21, 22 |
 *
 * \* subject to **BD-32** — item 20 lists the same conditions under "Automatically
 * Reject". `dpc.merit.rule_failure_status` selects which reading is in force.
 *
 * **Why rejection comes first.** A rejected candidate is out, so computing a score
 * for them and then discarding it would put a mark on a record that was never in
 * contention — and a score beside a rejection reads as a near miss.
 *
 * **Why CIR comes after the rule but before the score.** Item 19 says a candidate
 * with a justified gap *continues in the DPC*, so the gap cannot be allowed to read
 * as a merit failure: a CR that is missing is a CR that cannot be scored, and a
 * shortfall caused by its absence would be the officer paying for their department's
 * filing. But a candidate who does not satisfy the recruitment rule is not in
 * contention at all, whatever their records say — so the rule is asked first.
 *
 * ### Item 17 is the reason none of this rejects
 *
 * > *If the employee has not completed five years of service, allow available
 * > records only. The candidate shall not be rejected only because five years of
 * > service has not yet been completed.*
 *
 * So a short-service officer with justified gaps lands on `CIR`, not `Rejected` and
 * not `Not Eligible`. That is not a special case in the code — it falls out of
 * treating a justified gap as CIR and never as a rejection.
 *
 * Pure: no database, no clock, no configuration read *inside* a rule. Everything
 * arrives on the `OfficerRecord` or as an argument, which is what lets the gates be
 * golden-tested the way the formula already is.
 */
class CandidateGateService
{
    /**
     * Assess one candidate against every gate.
     *
     * `$hierarchyFailures` are the proposal-level findings from
     * {@see PromotionHierarchyService}. They are passed in rather than recomputed
     * per candidate because the post pair is the same for everybody — but they
     * produce a *per candidate* rejection, because a status is something a candidate
     * has.
     *
     * @param  list<string>  $hierarchyFailures
     * @param  list<int>  $mandatoryCrYears  the CR years the Class Master rule makes mandatory
     */
    public function assess(
        OfficerRecord $officer,
        array $hierarchyFailures,
        int $propertyYearsRequired,
        int $requiredCrCount,
        RemarkCode $ruleFailureStatus,
        bool $enforceQualification = false,
        array $mandatoryCrYears = [],
    ): CandidateGateVerdict {
        /*
         | The qualification comparison is **advisory by default** — BD-39.
         |
         | Item 12 says to validate the qualification against the Recruitment Rule
         | but does not say how the comparison is made, and the two sides of it are
         | not comparable as they stand: the rule names a *level* from the
         | Qualification Master ("Post-Graduate", "Graduate"), while the chart carries
         | the *degree* an operator typed ("M.A., B.Ed."). No string comparison knows
         | that an M.A. is a post-graduate degree — that is a mapping the Commission
         | owns and has not yet supplied.
         |
         | So an unmatched qualification is **reported and not applied**. Enforcing it
         | on the present data refuses officers who plainly hold the qualification,
         | which is the worst direction for this error to run: a promotion wrongly
         | refused is a promotion wrongly refused, and the portal would be the one
         | asserting it. `dpc.merit.qualification_gate` turns it on the day the
         | mapping exists, so a ruling is a setting rather than a code change.
         */
        $advisories = [];

        if (! $enforceQualification) {
            $mismatch = $this->qualificationMismatch($officer);

            if ($mismatch !== null) {
                $advisories[] = $mismatch;
            }
        }
        // ---- 1. Rejections (items 11, 13, 20) ----------------------------
        $rejections = $hierarchyFailures;

        $propertyGap = $this->missingPropertyYears($officer, $propertyYearsRequired);

        if ($propertyGap !== []) {
            $rejections[] = sprintf(
                'Property Return not available for mandatory %d continuous years — missing %s.',
                $propertyYearsRequired,
                implode(', ', $propertyGap),
            );
        }

        if ($rejections !== []) {
            return CandidateGateVerdict::rejected($rejections)->with($advisories);
        }

        // ---- 2. The recruitment rule (item 12) ---------------------------
        $ruleFailures = $this->recruitmentRuleFailures($officer, $enforceQualification);

        // The department's declarations (Coder Instructions rules 2.3 and 4.4).
        if ($officer->unsanctionedDeputation) {
            $ruleFailures[] = 'On deputation without a sanctioned setup, so not considered (Coder Instructions rule 2.3).';
        }

        if ($officer->refusedPromotionLastYear) {
            $ruleFailures[] = 'Refused promotion to this post last year, so unfit for it (Coder Instructions rule 4.4).';
        }

        if ($ruleFailures !== []) {
            return CandidateGateVerdict::ruleNotSatisfied($ruleFailures, $ruleFailureStatus)->with($advisories);
        }

        // ---- 3. Justified gaps (items 14, 15, 17, 19) --------------------
        $gapReasons = $this->gapFindings($officer, $requiredCrCount, $mandatoryCrYears);

        if ($gapReasons['citations'] !== []) {
            return CandidateGateVerdict::circulation($gapReasons['reasons'], $gapReasons['citations'])
                ->with($advisories);
        }

        // An *unjustified* shortfall is not CIR: item 19 opens that path only for a
        // gap the officer has explained and evidenced. Reported as a rule failure —
        // the mandatory record simply is not there, and nobody has said why.
        if ($gapReasons['unjustified'] !== []) {
            return CandidateGateVerdict::ruleNotSatisfied($gapReasons['unjustified'], $ruleFailureStatus)
                ->with($advisories);
        }

        return CandidateGateVerdict::passed()->with($advisories);
    }

    /**
     * Item 13: *"Validate the last five continuous years. If even one year is
     * missing, Candidate Status = Rejected."*
     *
     * **Continuous, and anchored on the most recent year the chart carries.** The
     * register says "the last five continuous years" without naming the anchor, and
     * the chart's own most recent property year is the only anchor the data offers —
     * a calendar anchor would make the answer depend on when the calculation ran,
     * which a reproducible calculation cannot afford. Recorded as **BD-37**.
     *
     * An officer with **no** property years at all is missing all five, which is the
     * plain reading and the one that refuses rather than waves through.
     *
     * @return list<int> the years missing from the window
     */
    public function missingPropertyYears(OfficerRecord $officer, int $required): array
    {
        if ($required < 1) {
            return [];
        }

        $recorded = array_keys(array_filter(
            $officer->propertyReturnYears,
            static fn (?bool $filed): bool => $filed === true,
        ));

        if ($recorded === []) {
            // Nothing filed at all. Reported as the window ending at the most recent
            // year the chart mentions, so the message names real years rather than
            // saying "five years" and leaving the officer to work out which.
            $anchor = $officer->mostRecentRecordYear();

            return $anchor === null
                ? []
                : range($anchor - $required + 1, $anchor);
        }

        $anchor = max($recorded);
        $window = range($anchor - $required + 1, $anchor);

        return array_values(array_diff($window, $recorded));
    }

    /**
     * Item 12: qualification, experience and promotion duration.
     *
     * Each is checked only when the recruitment rule actually prescribes it — a
     * Schedule-IV entry that imposes no qualification beyond holding the feeder post
     * is ordinary, and treating an absent requirement as an unmet one would fail
     * every candidate under such a rule.
     *
     * @return list<string>
     */
    public function recruitmentRuleFailures(OfficerRecord $officer, bool $enforceQualification = true): array
    {
        $failures = [];

        if ($enforceQualification) {
            $mismatch = $this->qualificationMismatch($officer);

            if ($mismatch !== null) {
                $failures[] = $mismatch;
            }
        }

        /*
         | Both service checks are made in months.
         |
         | A Schedule-IV entry may require 18 months, and an officer with 20 months of
         | qualifying service satisfies it. Comparing whole years would round them to
         | one and refuse them — which is why `min_experience_unit` exists on the
         | master in the first place.
         |
         | Reported in the unit the requirement was published in, though: an officer
         | told "5 year(s) required, 47 month(s) served" has to do the arithmetic
         | themselves to see how close they were.
         */
        if ($officer->requiredExperienceMonths !== null
            && $officer->serviceMonthsOnFeederPost !== null
            && $officer->serviceMonthsOnFeederPost < $officer->requiredExperienceMonths) {
            $failures[] = sprintf(
                'Promotion experience not completed: %s required on the Promotion From Post, %s served.',
                self::duration($officer->requiredExperienceMonths),
                self::duration($officer->serviceMonthsOnFeederPost),
            );
        }

        if ($officer->requiredPromotionDurationMonths !== null
            && $officer->serviceMonthsOnFeederPost !== null
            && $officer->serviceMonthsOnFeederPost < $officer->requiredPromotionDurationMonths) {
            $failures[] = sprintf(
                'Promotion duration not completed: %s of qualifying service required, %s served.',
                self::duration($officer->requiredPromotionDurationMonths),
                self::duration($officer->serviceMonthsOnFeederPost),
            );
        }

        return $failures;
    }

    /**
     * Items 14, 15 and 19: which mandatory records are missing, and whether the
     * officer has justified them.
     *
     * **CRs need not be continuous** (item 14 says so explicitly), so this counts
     * rather than looking for a window — the difference from the property return
     * above is the register's, not an inconsistency here.
     *
     * **A Loyalty Certificate belongs to a CR year** (item 15), so it is checked
     * against the years that carry a CR rather than against a calendar.
     *
     * @param  list<int>  $mandatoryCrYears
     * @return array{reasons: list<string>, citations: list<string>, unjustified: list<string>}
     */
    public function gapFindings(OfficerRecord $officer, int $requiredCrCount, array $mandatoryCrYears = []): array
    {
        $reasons = [];
        $citations = [];
        $unjustified = [];

        // ---- CRs (item 14) ----------------------------------------------
        $gradedYears = array_keys(array_filter(
            $officer->crGrades,
            static fn (?string $grade): bool => $grade !== null && $grade !== '',
        ));

        $shortfall = $requiredCrCount - count($gradedYears);

        if ($shortfall > 0) {
            $justifiedCrYears = $officer->justifiedCrGapYears;

            if (count($justifiedCrYears) >= $shortfall) {
                $reasons[] = sprintf(
                    '%d of the %d mandatory CRs are unavailable and have been justified with a reason and a '
                    .'supporting document, so the promotion is held rather than refused.',
                    $shortfall,
                    $requiredCrCount,
                );

                foreach ($justifiedCrYears as $year => $citation) {
                    $citations[] = $citation;
                }
            } else {
                $unjustified[] = sprintf(
                    'Only %d of the %d mandatory CRs are recorded, and %d of the missing year(s) carry no '
                    .'reason and no supporting document.',
                    count($gradedYears),
                    $requiredCrCount,
                    $shortfall - count($justifiedCrYears),
                );
            }
        }

        // ---- CR years the Class Master rule makes mandatory (2026-10-21) --
        // Each is recorded, or justified with its reason and document (rule 3.2):
        // a justified year holds the promotion (CIR), an unexplained one refuses it.
        foreach ($mandatoryCrYears as $year) {
            if (in_array($year, $gradedYears, true)) {
                continue;
            }

            $citation = $officer->justifiedCrGapYears[$year] ?? null;

            if ($citation !== null) {
                $reasons[] = sprintf('The CR for %d, mandatory under the promotion rule, is unavailable and has been justified with a reason and a supporting document, so the promotion is held rather than refused.', $year);
                $citations[] = $citation;
            } else {
                $unjustified[] = sprintf('The CR for %d is mandatory under the promotion rule and is not recorded, with no reason or supporting document.', $year);
            }
        }

        // ---- Loyalty certificates (item 15) ------------------------------
        $missingLoyalty = [];

        foreach ($gradedYears as $year) {
            if (($officer->loyaltyByYear[$year] ?? null) !== true) {
                $missingLoyalty[] = $year;
            }
        }

        foreach ($missingLoyalty as $year) {
            $citation = $officer->justifiedLoyaltyGapYears[$year] ?? null;

            if ($citation !== null) {
                $citations[] = $citation;
            } else {
                $unjustified[] = sprintf(
                    'The Loyalty Certificate for %d is not recorded and carries no reason or supporting document.',
                    $year,
                );
            }
        }

        if ($missingLoyalty !== [] && $unjustified === []) {
            $reasons[] = sprintf(
                'The Loyalty Certificate is unavailable for %s and has been justified, so the promotion is held '
                .'rather than refused.',
                implode(', ', $missingLoyalty),
            );
        }

        return [
            'reasons' => $reasons,
            'citations' => array_values(array_unique($citations)),
            'unjustified' => $unjustified,
        ];
    }

    /**
     * A month count as a government reader would write it.
     *
     * Whole years say "5 year(s)"; anything else keeps the months, because a
     * requirement published in months has to be answerable in months.
     */
    private static function duration(int $months): string
    {
        if ($months > 0 && $months % 12 === 0) {
            return sprintf('%d year(s)', intdiv($months, 12));
        }

        return sprintf('%d month(s)', $months);
    }

    /**
     * The qualification finding, worded once and used two ways.
     *
     * Returned as a *failure* when `dpc.merit.qualification_gate` enforces the check,
     * and as an *advisory* otherwise — see {@see assess()} and BD-39. One method so
     * the two readings can never drift into saying different things about the same
     * officer.
     */
    private function qualificationMismatch(OfficerRecord $officer): ?string
    {
        if ($officer->requiredQualifications === [] || $officer->meetsAnyRequiredQualification()) {
            return null;
        }

        return sprintf(
            'Qualification not satisfied: the recruitment rule requires %s, and the officer records %s.',
            implode(' or ', $officer->requiredQualifications),
            $officer->qualification === null || $officer->qualification === ''
                ? 'none'
                : $officer->qualification,
        );
    }
}
