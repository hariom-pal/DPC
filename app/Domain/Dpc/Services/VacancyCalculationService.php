<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\ValueObjects\VacancyBreakdown;
use App\Domain\Eligibility\Services\DpcRuleBook;
use App\Domain\Eligibility\Services\ZoneOfConsiderationService;
use App\Domain\Eligibility\ValueObjects\ZoneOfConsideration;
use App\Enums\ServiceClass;

/**
 * Vacancy and zone arithmetic for a DPC proposal (business directive,
 * 2026-08-22).
 *
 * **Reshaped by the change register of 2026-08-27.** This class was the vacancy
 * arithmetic; it is now the *zone* arithmetic plus a door onto
 * {@see VacancyEngine}, which owns the vacancy. The register moved every input out
 * of the officer's hands:
 *
 *   Working Strength  = rows on the Currently Working chart
 *   Base Vacancy      = Sanctioned Strength (Department Post Mapping) − Working
 *   Total Vacancy     = Base + Retirement + Deputation
 *   Promotion / DR    = Total split by the Recruitment Rule's percentage
 *   Zone of           = the M5 rule for this class pair, applied to
 *   Consideration       Total Vacancy
 *
 * **The vacancy is now persisted, and the reason it was not before no longer
 * holds.** It used to be a function of two numbers on the proposal, so storing it
 * would have created a second version of a truth a later edit could leave stale.
 * It is now a function of five things that live elsewhere and change underneath it
 * — the sanctioned strength on the mapping, the promotion share on the rule, and
 * each officer's retirement and deputation dates — so it cannot be recomputed
 * later and guaranteed to give the answer the Commission acted on.
 * `dpc_vacancy_calculations` records it, append-only. The zone remains derived.
 *
 * **Why this class implements neither the zone nor the vacancy.** Both already
 * exist as engines — `ZoneOfConsiderationService` and `VacancyEngine` — and AD-19
 * says a rule is re-applied through the existing mechanism and never through a
 * second code path. So this delegates to both. A DPC's
 * zone shown on the proposal screen and the zone its calculation runs under are
 * therefore the same number by construction, not by two implementations
 * agreeing. The alternative — writing `(vacancy * 2) + 4` here — would have
 * been three lines and would have drifted the first time a notification changed
 * the multiplier.
 *
 * **Two places where the printed rule and a flat `(V × 2) + 4` disagree**, both
 * inherited from the deck and both preserved here rather than papered over:
 *
 *  1. **One vacancy.** C-deck sizing gives *top 5* for a single vacancy, not
 *     `2 × 1 + 4 = 6`. `single_vacancy_size` in `dpc.scoring.zone.rules`.
 *  2. **Class pairs other than Class-I → Class-I.** The deck states no formula
 *     for them ("शेष" / other cases): every officer meeting the experience
 *     qualification is considered, with no arithmetic cap. For those pairs the
 *     zone is *uncapped* and {@see requiredSeniorityCount()} returns null, so
 *     the exact-count gate on the seniority list cannot and does not apply.
 *
 * Both are configuration, so a ruling that the flat formula should govern
 * everywhere is an environment change (`DPC_ZONE_*`) plus a golden-test update
 * — not a change to this class.
 */
class VacancyCalculationService
{
    public function __construct(
        private readonly ZoneOfConsiderationService $zones,
        private readonly DpcRuleBook $ruleBook,
    ) {}

    /**
     * Officers on the Currently Working chart (S16).
     *
     * Counted from the saved rows rather than from anything the officer typed:
     * the Excel import writes these rows directly, so a stored total would be
     * wrong the moment a file was committed.
     */
    public function workingOfficerCount(DpcProposal $proposal): int
    {
        return $proposal->workingOfficers()->count();
    }

    /**
     * `Base + Retirement + Deputation`, or null while the sanctioned strength is
     * not recorded on the Department Post Mapping.
     *
     * Null rather than zero: "no vacancy" and "we do not yet know" are different
     * facts, and only the second one should leave the screen pointing at the master
     * rather than showing a confident 0.
     */
    public function totalVacancy(DpcProposal $proposal, ?int $sanctioned = null, ?int $working = null): ?int
    {
        /*
         | **Total Vacancy stopped being `sanctioned − working` on 2026-08-27.**
         |
         | Items 3 to 5 add a retirement and a deputation vacancy to that
         | difference, so the old subtraction is now the *Base* Vacancy and this is
         | Base + Retirement + Deputation. Delegated to `VacancyEngine` rather than
         | reimplemented, for the same reason this class already delegates the zone
         | to `ZoneOfConsiderationService`: a rule is applied through one mechanism,
         | never through a second code path that agrees today.
         |
         | The `$sanctioned` and `$working` overrides remain for the callers that
         | ask "what would the vacancy be if the chart had N officers" — the Form
         | Request judging an arriving submission is one. When either is given the
         | base is computed from it and the two additional counts still come from
         | the saved chart, which is the only source they have.
         */
        if ($sanctioned === null && $working === null) {
            return app(VacancyEngine::class)->calculate($proposal)->totalVacancy;
        }

        $sanctioned ??= app(SanctionedStrengthSource::class)->forProposal($proposal) ?? $proposal->sanctioned_post_count;

        if ($sanctioned === null) {
            return null;
        }

        $breakdown = app(VacancyEngine::class)->calculate($proposal);

        return $sanctioned
            - ($working ?? $this->workingOfficerCount($proposal))
            + $breakdown->retirementVacancy
            + $breakdown->deputationVacancy;
    }

    /**
     * The full breakdown, for the screens and the audit record.
     *
     * The nine figures item 8 names live on `VacancyBreakdown`; this is the door to
     * them from the M4 code that already depends on this class.
     */
    public function breakdown(DpcProposal $proposal): VacancyBreakdown
    {
        return app(VacancyEngine::class)->calculate($proposal);
    }

    /**
     * The vacancy breakdown as saved: the sum of every `(type, category)` row.
     *
     * This is the same total the scoring engine works from — it sums the very
     * same rows — which is what makes the balance invariant below more than a
     * presentational nicety: it forces the number M5 fills seats against to
     * equal `sanctioned − working`.
     */
    public function classifiedVacancyTotal(DpcProposal $proposal): int
    {
        return (int) $proposal->vacancies()->sum('count');
    }

    /**
     * The zone for this proposal, or null when the vacancy is not yet knowable.
     *
     * **Sized on the Promotion Vacancy, not the Total** (business directive,
     * 2026-09-02). A committee considers candidates for the seats it is going to
     * fill, and a Direct Recruitment vacancy is not one of them — sizing the zone
     * on the total would call up candidates for chairs this DPC has no claim on,
     * and the allotment that follows would be for the wrong number.
     *
     * `availableOfficers` is the seniority list as it stands. It affects only
     * `effectiveSize`; `size` — the number the gate uses — is a function of the
     * vacancy and the class pair alone.
     */
    public function zone(DpcProposal $proposal, ?int $vacancy = null): ?ZoneOfConsideration
    {
        $vacancy ??= $this->promotionVacancy($proposal);

        if ($vacancy === null) {
            return null;
        }

        return $this->zones->determine(
            fromClass: $this->classOf($proposal, 'promotionFromPost'),
            toClass: $this->classOf($proposal, 'promotionToPost'),
            vacancies: max(0, $vacancy),
            availableOfficers: $proposal->seniorityEntries()->count(),
            rules: $this->ruleBook->ruleSetFor($proposal),
        );
    }

    /**
     * How many officers the seniority list must carry, or null when no exact
     * count can be required.
     *
     * Null in three cases, and the difference matters to the caller:
     *   - the sanctioned strength is not entered yet, so there is no vacancy;
     *   - the class pair has no zone formula, so the deck imposes no cap;
     *   - the vacancy is zero or negative, so there is nothing to fill.
     */
    public function requiredSeniorityCount(DpcProposal $proposal, ?int $vacancy = null): ?int
    {
        $zone = $this->zone($proposal, $vacancy);

        if ($zone === null || $zone->isUncapped() || $zone->size === null || $zone->size < 1) {
            return null;
        }

        return $zone->size;
    }

    /**
     * Does the officer count fit inside the sanctioned strength?
     *
     * The rule is `working <= sanctioned`. Equality is allowed and means a
     * fully-staffed post with no vacancy — unusual for a DPC but not invalid,
     * and refusing it here would be inventing a rule.
     */
    public function exceedsSanctionedStrength(DpcProposal $proposal, ?int $sanctioned = null, ?int $working = null): bool
    {
        return app(VacancyEngine::class)->calculate($proposal)->exceedsSanctionedStrength();
    }

    /**
     * The seats this DPC will actually fill.
     *
     * `promotion chairs − promotion chairs held` since the business directive of
     * 2026-09-02. Everything the committee is sized and allotted against reads
     * this rather than `totalVacancy`, which also carries the Direct Recruitment
     * share.
     */
    public function promotionVacancy(DpcProposal $proposal): ?int
    {
        return app(VacancyEngine::class)->calculate($proposal)->promotionVacancy;
    }

    /**
     * The one wording for a seniority-list count mismatch.
     *
     * Defined once and used by the Form Request, the Excel commit and the
     * submission checklist, so an officer who meets the same refusal by three
     * different routes is told the same thing each time.
     */
    public function seniorityCountMessage(int $required): string
    {
        return "The uploaded Seniority List must contain exactly {$required} officer"
            .($required === 1 ? '' : 's')
            .' based on the calculated Zone of Consideration.';
    }

    /**
     * A read-model for the screens, so the Blade views do no arithmetic.
     *
     * @return array{
     *     sanctioned: int|null,
     *     working: int,
     *     total_vacancy: int|null,
     *     classified_total: int,
     *     zone: ZoneOfConsideration|null,
     *     required_seniority: int|null,
     *     seniority_entered: int,
     *     promotion_vacancy: int|null,
     *     direct_vacancy: int|null,
     *     exceeds_sanctioned: bool,
     *     quota_overfilled: bool,
     *     chart_incomplete: bool,
     *     balanced: bool,
     *     breakdown: VacancyBreakdown
     * }
     */
    public function summary(DpcProposal $proposal): array
    {
        $breakdown = $this->breakdown($proposal);
        $working = $breakdown->workingStrength;
        $vacancy = $breakdown->totalVacancy;
        $promotion = $breakdown->promotionVacancy;
        $classified = $this->classifiedVacancyTotal($proposal);

        return [
            'sanctioned' => $breakdown->sanctionedStrength,
            'working' => $working,
            'total_vacancy' => $vacancy,

            /*
             | The seats this committee will fill, which since the business
             | directive of 2026-09-02 is not the same number as the total. Every
             | gate below reads this one.
             */
            'promotion_vacancy' => $promotion,
            'direct_vacancy' => $breakdown->directRecruitmentVacancy,

            'classified_total' => $classified,
            'zone' => $this->zone($proposal, $promotion),
            'required_seniority' => $this->requiredSeniorityCount($proposal, $promotion),
            'seniority_entered' => $proposal->seniorityEntries()->count(),

            'exceeds_sanctioned' => $breakdown->exceedsSanctionedStrength(),

            // A quota filled beyond what the rule allows — a `direct` row against a
            // 100%-promotion post. A data fault, not a finding.
            'quota_overfilled' => $breakdown->quotaOverfilled(),

            // Fewer rows than sanctioned posts: the unlisted chairs count as
            // vacant but have no category to be classified under.
            'chart_incomplete' => $breakdown->chartIsIncomplete(),

            /*
             | The stored rows balance against the **promotion** vacancy, because
             | those are the only rows written: a Direct Recruitment seat is
             | reported but never stored as one this DPC may fill.
             */
            'balanced' => $promotion !== null && $classified === max(0, $promotion),

            'breakdown' => $breakdown,
        ];
    }

    /**
     * The class of a post, defaulting to Class-I when unset.
     *
     * A post without a class is a master-data gap rather than a promotion
     * rule, and Class-I is the pair the deck actually specifies a formula for —
     * so defaulting there keeps the stricter behaviour. The alternative,
     * treating it as "no formula", would silently switch the seniority gate off
     * because a master row was incomplete.
     */
    private function classOf(DpcProposal $proposal, string $relation): ServiceClass
    {
        $post = $proposal->{$relation};
        $level = $post->class_level ?? null;

        if ($level instanceof ServiceClass) {
            return $level;
        }

        return $level === null ? ServiceClass::I : (ServiceClass::tryFrom((string) $level) ?? ServiceClass::I);
    }
}
