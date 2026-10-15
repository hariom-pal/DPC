<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\ValueObjects\VacancyBreakdown;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\VacancyType;
use App\Enums\MasterStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Vacancy Calculation Engine (change register of 2026-08-27, Part 1).
 *
 * > *The system should automatically calculate Promotion DPC vacancies. The
 * > Administrative Officer should not manually enter vacancy figures.*
 *
 * That sentence is the whole design. Every figure below is derived from a record
 * somebody else is responsible for — the Government Order behind the Department
 * Post Mapping, the Recruitment Rule, the uploaded Working Officer List — and none
 * of them is an input on the vacancy screen.
 *
 * ```
 * Base Vacancy    = Sanctioned Strength − Working Strength          (items 1, 2)
 * Total Vacancy   = Base + Retirement + Deputation                  (items 3, 4, 5)
 * Promotion       = Total × Promotion%   (from the Recruitment Rule) (item 6)
 * Direct          = Total − Promotion                               (item 7)
 * ```
 *
 * ### Where each number comes from, and why it is not the officer's
 *
 * | Figure | Source | Why not typed |
 * |---|---|---|
 * | Sanctioned Strength | `department_posts.sanctioned_strength` | It is what a Government Order says, and the mapping is where this portal keeps that |
 * | Working Strength | `dpc_working_officers` row count | The Excel import writes those rows, so any stored total is wrong the moment a file is committed |
 * | Retirement / Deputation | each officer's own dates | Two officers retiring in the window is two vacancies; a typed total cannot be checked against anybody |
 * | Promotion % | `recruitment_rules.promotion_recruitment_pct` | Item 6 says *only* from the Recruitment Rule |
 *
 * ### Pure, and deliberately so
 *
 * This class reads models it is handed and returns a value object. It writes
 * nothing — `VacancyCalculationRecorder` persists the audit row — and it holds no
 * clock: the vacancy period is derived from the proposal's **DPC Year**, not from
 * today, so recalculating an old proposal in December gives the same answer it gave
 * in March. That is what makes the golden tests meaningful.
 *
 * ### Two things it does not decide
 *
 *  - **The vacancy period.** Item 3 stated 1 January to 30 November of the DPC
 *    Year; rule 1.5 of the DPC Understanding Document reckons vacancies to 31
 *    December, which `dpc.vacancy.period` now defaults to (BD-34). Recorded
 *    calculations keep the window they were made with.
 *  - **The rounding of a fractional promotion share.** Part 5 defers it and item 8
 *    requires the figure to be shown, so `dpc.vacancy.promotion_rounding` applies a
 *    stated default and the exact value travels beside it (BD-33).
 */
class VacancyEngine
{
    /**
     * Calculate every figure the register's summary names.
     *
     * Returns a breakdown even when it cannot be completed: a mapping with no
     * sanctioned strength or a rule with no promotion share yields
     * `isComplete() === false` and null figures rather than an exception, because
     * the screen has to be able to say *which* master is incomplete. An exception
     * here would be a 500 on a data-entry gap.
     */
    public function calculate(DpcProposal $proposal): VacancyBreakdown
    {
        $proposal->loadMissing('promotionToPost', 'workingOfficers');

        [$periodFrom, $periodTo] = $this->period($proposal);

        $toPost = $proposal->promotionToPost;
        $sanctioned = $toPost?->sanctioned_strength;

        /*
         | **The chart lists posts, not only people** (business directive,
         | 2026-09-02), and each occupied post says which quota it is consuming.
         |
         | So the arithmetic runs from the establishment downwards rather than from
         | a finished total outwards:
         |
         |     promotion chairs  = sanctioned × promotion %
         |     direct chairs     = sanctioned − promotion chairs
         |     promotion vacancy = promotion chairs − officers who came by
         |                         promotion and still hold their chair
         |     direct vacancy    = direct chairs − the same for direct recruits
         |
         | **This replaced `Total × promotion %`, and the two are not the same
         | thing.** The old split multiplied a finished vacancy by the rule's share,
         | which said "all four vacancies are the DPC's" for a 100%-promotion post
         | even when a chair was held by a direct recruit. The subtraction cannot:
         | every chair is accounted to a quota by a row a department filled in, and
         | a chair the DPC has no claim on is visibly not counted.
         |
         | The percentage is still read — it is what divides the chairs — but it no
         | longer *produces* the promotion figure.
         */
        $rule = $this->recruitmentRule($proposal);
        $percentage = $rule?->promotion_recruitment_pct;

        $working = $proposal->workingOfficers->filter(
            static fn (DpcWorkingOfficer $officer): bool => $officer->isWorking()
        )->count();

        $vacant = $proposal->workingOfficers->filter(
            static fn (DpcWorkingOfficer $officer): bool => $officer->isVacant()
        )->count();

        $retirement = $this->retiring($proposal, $periodFrom, $periodTo);
        $deputation = $this->onDeputation($proposal, $periodFrom, $periodTo);

        $held = $this->chairsHeld($proposal, $periodFrom, $periodTo);

        [
            'promotion_chairs' => $promotionChairs,
            'direct_chairs' => $directChairs,
            'promotion_vacancy' => $promotion,
            'direct_vacancy' => $direct,
        ] = $this->quotaPosition(
            $sanctioned,
            $percentage === null ? null : (string) $percentage,
            $held[DpcWorkingOfficer::SOURCE_PROMOTION],
            $held[DpcWorkingOfficer::SOURCE_DIRECT],
        );

        /*
         | **The total needs the sanctioned strength, not the rule.**
         |
         | It is the number of chairs nobody substantively holds, which is a fact
         | about the chart alone — a post with three empty chairs has three
         | vacancies whether or not anyone has told the portal how they are to be
         | filled. Only the *split* needs the Recruitment Rule, and a proposal
         | waiting for one must still be able to show its vacancy.
         |
         | The two agree by construction rather than by coincidence:
         | `(promotion chairs − promotion held) + (direct chairs − direct held)`
         | is `sanctioned − held`, because the chairs sum to the sanctioned
         | strength and deputation holds neither quota.
         */
        $total = $sanctioned === null
            ? null
            : $sanctioned - ($held[DpcWorkingOfficer::SOURCE_PROMOTION] + $held[DpcWorkingOfficer::SOURCE_DIRECT]);

        /*
         | Base Vacancy keeps its meaning for the screen — the chairs standing
         | empty right now — while the quota figures above are what the DPC acts
         | on. It is reported, not used in the sum.
         */
        $base = $vacant;

        return new VacancyBreakdown(
            sanctionedStrength: $sanctioned,
            workingStrength: $working,
            chartRows: $proposal->workingOfficers->count(),
            baseVacancy: $base,
            retirementVacancy: $retirement->count(),
            deputationVacancy: $deputation->count(),
            totalVacancy: $total,
            promotionPercentage: $percentage === null ? null : (string) $percentage,
            promotionChairs: $promotionChairs,
            directChairs: $directChairs,
            promotionChairsHeld: $held[DpcWorkingOfficer::SOURCE_PROMOTION],
            directChairsHeld: $held[DpcWorkingOfficer::SOURCE_DIRECT],
            deputationChairsHeld: $held[DpcWorkingOfficer::SOURCE_DEPUTATION],
            promotionVacancy: $promotion,
            directRecruitmentVacancy: $direct,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            roundingRule: $this->roundingRule(),
            contributors: [...$retirement->all(), ...$deputation->all()],
            sanctionedFromPostId: $toPost?->id,
            recruitmentRuleId: $rule?->id,
        );
    }

    /**
     * The quota arithmetic on its own, from counts rather than from a chart.
     *
     *     promotion chairs  = sanctioned × promotion %   (rounded, see below)
     *     direct chairs     = sanctioned − promotion chairs
     *     promotion vacancy = promotion chairs − promotion chairs held
     *     direct vacancy    = direct chairs − direct chairs held
     *
     * `calculate()` reaches it from a Working Officers chart; the Vacancy Master
     * reaches it from the year-wise working strength the CGPSC Administrator
     * records. One implementation, so the two cannot disagree about a quota.
     *
     * Null throughout while the sanctioned strength or the promotion share is
     * unknown — the caller reports which.
     *
     * @return array{promotion_chairs: int|null, direct_chairs: int|null, promotion_vacancy: int|null, direct_vacancy: int|null, promotion_exact: float|null}
     */
    public function quotaPosition(?int $sanctioned, ?string $percentage, int $heldPromotion, int $heldDirect): array
    {
        [$promotionChairs, $directChairs] = $this->chairsByQuota($sanctioned, $percentage);

        return [
            'promotion_chairs' => $promotionChairs,
            'direct_chairs' => $directChairs,
            'promotion_vacancy' => $promotionChairs === null ? null : $promotionChairs - $heldPromotion,
            'direct_vacancy' => $directChairs === null ? null : $directChairs - $heldDirect,
            'promotion_exact' => $sanctioned === null || $percentage === null
                ? null
                : $sanctioned * ((float) $percentage / 100),
        ];
    }

    /**
     * The rounding rule in force, for a screen that has to state it.
     */
    public function rounding(): string
    {
        return $this->roundingRule();
    }

    /**
     * The Recruitment Rule's share, applied to the **chairs** rather than to a
     * finished vacancy.
     *
     * Null on either side while a master is incomplete, so the screen can say
     * which one rather than showing a confident zero.
     *
     * Direct takes the **remainder**, never its own `sanctioned × direct %`. Two
     * independent multiplications of the same strength do not have to add back up
     * to it — at 33.33/66.67 both floor and a chair goes missing — and a chair
     * that belongs to neither quota is a chair nobody may fill.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function chairsByQuota(?int $sanctioned, ?string $percentage): array
    {
        if ($sanctioned === null || $percentage === null) {
            return [null, null];
        }

        $exact = $sanctioned * ((float) $percentage / 100);

        $promotion = match ($this->roundingRule()) {
            'ceil' => (int) ceil($exact),
            'round' => (int) round($exact, 0, PHP_ROUND_HALF_UP),
            default => (int) floor($exact),
        };

        $promotion = max(0, min($sanctioned, $promotion));

        return [$promotion, $sanctioned - $promotion];
    }

    /**
     * How many chairs of each quota are substantively held, keyed by source.
     *
     * `deputation` is counted too, and reported, even though it holds no quota
     * chair — the screen has to be able to say *why* a chair is free, and "an
     * officer is sitting in it on deputation" is the answer a Chairman will ask
     * for. It is deliberately not subtracted from either quota.
     *
     * @return array<string, int>
     */
    private function chairsHeld(DpcProposal $proposal, Carbon $from, Carbon $to): array
    {
        $held = array_fill_keys(DpcWorkingOfficer::sources(), 0);

        foreach ($proposal->workingOfficers as $officer) {
            if ($officer->isVacant() || $officer->source === null) {
                continue;
            }

            if ($officer->source === DpcWorkingOfficer::SOURCE_DEPUTATION) {
                $held[DpcWorkingOfficer::SOURCE_DEPUTATION]++;

                continue;
            }

            if ($officer->substantivelyOccupies($from, $to)) {
                $held[$officer->source]++;
            }
        }

        return $held;
    }

    /**
     * The vacancy rows this DPC will fill, derived from the chart (business
     * directive, 2026-09-02).
     *
     * **Only the promotion quota is written.** The scoring engine fills seats from
     * the sum of these rows, so a Direct Recruitment vacancy stored here would be
     * promoted against — *"direct hai to humhe usse koi lena dena nahi hai."* The
     * direct figure is reported on the screen and recorded in the audit row; it is
     * not a seat this committee may fill.
     *
     * **The count comes from the quota subtraction; the categories come from the
     * chairs.** `promotion vacancy` is `promotion chairs − promotion chairs held`,
     * which is a number and not a set of rows. So the free chairs are walked in
     * serial order and their reservation categories taken until that number is
     * met, and a chair held by a direct recruit is skipped because his chair falls
     * to Direct Recruitment.
     *
     * **The order is the assumption in here.** Nothing in the register says which
     * roster point a promotion vacancy answers to when several chairs are free at
     * once, and this portal holds no roster-point arithmetic. Serial order is the
     * one rule that is visible on the screen the department filled in, rather than
     * one this engine invented; a roster-driven attribution is a business decision
     * that would replace it.
     *
     * The grounds are the register's own: an empty chair is `clear`, and an officer
     * leaving frees his chair on the ground he arrived by — *"promotion se aaya tha
     * to promotion se 1 vacancy badh gayee."*
     *
     * @return list<array{vacancy_type_id: int, reservation_category_id: int, count: int}>
     */
    public function classification(DpcProposal $proposal): array
    {
        $proposal->loadMissing('workingOfficers');

        [$from, $to] = $this->period($proposal);

        $breakdown = $this->calculate($proposal);
        $seats = $breakdown->promotionVacancy;

        if ($seats === null || $seats < 1) {
            return [];
        }

        /** @var array<string, int> $types */
        $types = VacancyType::query()->pluck('id', 'code')->all();

        /** @var array<string, int> $tally  "typeCode|categoryId" => count */
        $tally = [];
        $taken = 0;

        foreach ($proposal->workingOfficers->sortBy('sn') as $officer) {
            if ($taken >= $seats) {
                break;
            }

            $ground = $officer->vacancyGround($from, $to);

            // Null means the chair is held for the year, or held by a direct
            // recruit whose chair is not this DPC's to fill.
            if ($ground === null || ! isset($types[$ground])) {
                continue;
            }

            $key = $ground.'|'.$officer->reservation_category_id;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
            $taken++;
        }

        $rows = [];

        foreach ($tally as $key => $count) {
            [$ground, $categoryId] = explode('|', $key);

            $rows[] = [
                'vacancy_type_id' => $types[$ground],
                'reservation_category_id' => (int) $categoryId,
                'count' => $count,
            ];
        }

        return $rows;
    }

    /**
     * The vacancy period for this proposal's DPC Year.
     *
     * **Derived from the DPC Year, never from today.** A proposal for 2026
     * recalculated in 2027 must give the same window it gave in 2026, or a
     * concluded DPC could not be reproduced. Falls back to the proposal date's year
     * for a legacy proposal that records no DPC Year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(DpcProposal $proposal): array
    {
        /*
         | The DPC Year, falling back to the year the proposal was raised in.
         |
         | `proposal_date` is NOT NULL, so there is no third fallback to `today()` —
         | and there must not be. A period derived from the clock would make the same
         | calculation give a different answer next year, which is the one thing an
         | auditable vacancy figure cannot do.
         */
        $year = $proposal->dpc_year ?: $proposal->proposal_date->year;

        return $this->periodForYear((int) $year);
    }

    /**
     * The Vacancy Year: 1 January to 31 December of `$year`, both inclusive, as
     * `dpc.vacancy.period` states it. Shared by DPC proposals and the Vacancy
     * Master so the two use one window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodForYear(int $year): array
    {
        $from = (array) config('dpc.vacancy.period.from', ['month' => 1, 'day' => 1]);
        $to = (array) config('dpc.vacancy.period.to', ['month' => 12, 'day' => 31]);

        return [
            Carbon::create($year, (int) ($from['month'] ?? 1), (int) ($from['day'] ?? 1))->startOfDay(),
            Carbon::create($year, (int) ($to['month'] ?? 12), (int) ($to['day'] ?? 31))->endOfDay(),
        ];
    }

    /**
     * Officers on the Promotion To Post who retire inside the window (item 3).
     *
     * > *If an officer occupying the Promotion Post retires between 1 January and
     * > 30 November of the DPC Year, automatically increase one additional
     * > vacancy.*
     *
     * **One vacancy per retiring officer.** The register's sentence is about *an*
     * officer, so two officers retiring in the window free two posts. Reading it as
     * one vacancy in total would mean the second retirement produced nothing, which
     * no establishment rule works that way.
     *
     * An officer with no retirement date recorded contributes nothing. That is not
     * a defect: most officers on a chart are not retiring in the DPC Year, and the
     * column is nullable precisely so the upload does not demand a date for all of
     * them.
     *
     * @return Collection<int, array{sn: int, name: string, ground: string, detail: string}>
     */
    public function retiring(DpcProposal $proposal, Carbon $from, Carbon $to): Collection
    {
        /** @var Collection<int, array{sn: int, name: string, ground: string, detail: string}> $rows */
        $rows = $proposal->workingOfficers
            ->filter(fn (DpcWorkingOfficer $officer): bool => $officer->isWorking()
                && $officer->date_of_retirement !== null
                && $officer->date_of_retirement->betweenIncluded($from, $to))
            ->map(fn (DpcWorkingOfficer $officer): array => [
                'sn' => (int) $officer->sn,
                'name' => $officer->displayName(),
                'ground' => 'retirement',
                'detail' => sprintf('Retires on %s', $officer->date_of_retirement->format('d-m-Y')),
            ])
            ->values();

        return $rows;
    }

    /**
     * Officers on the Promotion To Post whose deputation overlaps the window
     * (item 4).
     *
     * > *If an officer occupying the Promotion Post remains on deputation during
     * > the same vacancy period, automatically increase one additional vacancy.*
     *
     * **Overlap, not containment.** A deputation running from the previous November
     * to the following February covers the whole window without starting or ending
     * inside it, and the post is vacant throughout. The test is therefore
     * `from <= periodTo && (to === null || to >= periodFrom)`.
     *
     * **A null end date means still on deputation**, which is exactly the case that
     * frees a post. Treating it as "no period" would exclude the clearest instance
     * of the rule.
     *
     * @return Collection<int, array{sn: int, name: string, ground: string, detail: string}>
     */
    public function onDeputation(DpcProposal $proposal, Carbon $from, Carbon $to): Collection
    {
        /** @var Collection<int, array{sn: int, name: string, ground: string, detail: string}> $rows */
        $rows = $proposal->workingOfficers
            ->filter(fn (DpcWorkingOfficer $officer): bool => $officer->isWorking()
                && $officer->isOnDeputationDuring($from, $to))
            ->map(fn (DpcWorkingOfficer $officer): array => [
                'sn' => (int) $officer->sn,
                'name' => $officer->displayName(),
                'ground' => 'deputation',
                'detail' => sprintf(
                    'On deputation from %s %s',
                    $officer->deputation_from?->format('d-m-Y') ?? '—',
                    $officer->deputation_to === null
                        ? '(no return date recorded)'
                        : 'to '.$officer->deputation_to->format('d-m-Y'),
                ),
            ])
            ->values();

        return $rows;
    }

    /**
     * The Recruitment Rule that states this post's promotion share (item 6).
     *
     * The **active** rule for the Promotion To Post, effective on or before the
     * proposal date and not yet lapsed. Reading a draft rule would let an
     * unapproved percentage decide how many officers are promoted.
     *
     * A proposal that already records `recruitment_rule_id` uses that one, because
     * a DPC runs under the terms that sanctioned it — the same doctrine as
     * everywhere else here. Only a proposal with none falls back to the search.
     */
    public function recruitmentRule(DpcProposal $proposal): ?RecruitmentRule
    {
        if ($proposal->recruitment_rule_id !== null) {
            return RecruitmentRule::query()->find($proposal->recruitment_rule_id);
        }

        return $this->recruitmentRuleFor([(int) $proposal->promotion_to_post_id], $proposal->proposal_date ?? Carbon::today());
    }

    /**
     * The active rule in force on `$on` for any of the given post mappings — one
     * post may be several effective-dated mapping rows. Shared with the Vacancy
     * Master, which asks as of 1 January of the Vacancy Year.
     *
     * @param  list<int>  $postIds
     */
    public function recruitmentRuleFor(array $postIds, Carbon $on): ?RecruitmentRule
    {
        return RecruitmentRule::query()
            ->whereIn('post_id', $postIds)
            ->where('status', MasterStatus::Active->value)
            ->where('effective_from', '<=', $on)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Which rounding rule is in force. Validated, so a mistyped setting falls back
     * to the documented default rather than silently behaving as `floor` anyway.
     */
    private function roundingRule(): string
    {
        $configured = (string) config('dpc.vacancy.promotion_rounding', 'floor');

        return in_array($configured, ['floor', 'round', 'ceil'], true) ? $configured : 'floor';
    }
}
