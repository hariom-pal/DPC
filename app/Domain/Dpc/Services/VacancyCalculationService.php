<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\ZoneOfConsiderationService;
use App\Domain\Eligibility\ValueObjects\ZoneOfConsideration;
use App\Enums\ServiceClass;

/**
 * Vacancy and zone arithmetic for a DPC proposal (business directive,
 * 2026-08-22).
 *
 * Three derived numbers, none of them stored:
 *
 *   Working Officers  = rows on the Currently Working chart
 *   Total Vacancy     = Sanctioned Post Count − Working Officers
 *   Zone of           = the M5 rule for this class pair, applied to
 *   Consideration       Total Vacancy
 *
 * **Nothing here is persisted, and that is deliberate.** All three are
 * functions of data the proposal already holds, so storing them would create a
 * second version of the truth that a later edit could leave stale — the exact
 * failure the evaluation chart's stored/derived split was designed to avoid.
 * They are computed on read, and the invariants below are enforced at every
 * write.
 *
 * **Why this class does not implement the zone formula.** The zone already
 * exists: `Eligibility\Services\ZoneOfConsiderationService` is the pure engine
 * M5 scores with, and AD-19 says a rule is re-applied through the existing
 * mechanism and never through a second code path. So this delegates. A DPC's
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
     * `Sanctioned Post Count − Working Officers`, or null while the sanctioned
     * strength has not been entered.
     *
     * Null rather than zero: "no vacancy" and "we do not yet know" are
     * different facts, and only the second one should leave the screen waiting
     * for input rather than showing a confident 0.
     */
    public function totalVacancy(DpcProposal $proposal, ?int $sanctioned = null, ?int $working = null): ?int
    {
        $sanctioned ??= $proposal->sanctioned_post_count;

        if ($sanctioned === null) {
            return null;
        }

        return $sanctioned - ($working ?? $this->workingOfficerCount($proposal));
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
     * `availableOfficers` is the seniority list as it stands. It affects only
     * `effectiveSize`; `size` — the number the gate uses — is a function of the
     * vacancy and the class pair alone.
     */
    public function zone(DpcProposal $proposal, ?int $vacancy = null): ?ZoneOfConsideration
    {
        $vacancy ??= $this->totalVacancy($proposal);

        if ($vacancy === null) {
            return null;
        }

        return $this->zones->determine(
            fromClass: $this->classOf($proposal, 'postFromCadre'),
            toClass: $this->classOf($proposal, 'postToCadre'),
            vacancies: max(0, $vacancy),
            availableOfficers: $proposal->seniorityEntries()->count(),
            rules: RuleSet::fromConfig(),
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
        $vacancy = $this->totalVacancy($proposal, $sanctioned, $working);

        return $vacancy !== null && $vacancy < 0;
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
     *     exceeds_sanctioned: bool,
     *     balanced: bool
     * }
     */
    public function summary(DpcProposal $proposal): array
    {
        $working = $this->workingOfficerCount($proposal);
        $vacancy = $this->totalVacancy($proposal, working: $working);
        $classified = $this->classifiedVacancyTotal($proposal);

        return [
            'sanctioned' => $proposal->sanctioned_post_count,
            'working' => $working,
            'total_vacancy' => $vacancy,
            'classified_total' => $classified,
            'zone' => $this->zone($proposal, $vacancy),
            'required_seniority' => $this->requiredSeniorityCount($proposal, $vacancy),
            'seniority_entered' => $proposal->seniorityEntries()->count(),
            'exceeds_sanctioned' => $vacancy !== null && $vacancy < 0,
            'balanced' => $vacancy !== null && $classified === $vacancy,
        ];
    }

    /**
     * The class of a cadre, defaulting to Class-I when unset.
     *
     * A cadre without a class is a master-data gap rather than a promotion
     * rule, and Class-I is the pair the deck actually specifies a formula for —
     * so defaulting there keeps the stricter behaviour. The alternative,
     * treating it as "no formula", would silently switch the seniority gate off
     * because a master row was incomplete.
     */
    private function classOf(DpcProposal $proposal, string $relation): ServiceClass
    {
        $cadre = $proposal->{$relation};
        $level = $cadre->class_level ?? null;

        if ($level instanceof ServiceClass) {
            return $level;
        }

        return $level === null ? ServiceClass::I : (ServiceClass::tryFrom((string) $level) ?? ServiceClass::I);
    }
}
