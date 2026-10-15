<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\Services\CommitteeRuleResolver;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Enums\MasterStatus;
use RuntimeException;

/**
 * Brings a draft proposal up to the completeness the S14→S18 sequence requires
 * before it can be submitted (M4 Phase 7, final workflow validation).
 *
 * Only sections that are actually incomplete are filled, so a test that has
 * already populated the section it cares about keeps its own data — the helper
 * supplies the surrounding sections that the submit gate insists on.
 *
 * **Since the vacancy directive of 2026-08-22 the numbers have to agree with
 * each other**, which is the whole point of the directive and makes this helper
 * slightly more interesting than it was:
 *
 *   Total Vacancy = Sanctioned − Working, and the vacancy rows must sum to it;
 *   the Seniority List must carry exactly the Zone of Consideration's count.
 *
 * So the helper cannot fill sections independently any more. It picks a
 * self-consistent set — and where a test has already written its own seniority
 * list, it works *backwards* from that list's length to the vacancy that
 * justifies it, rather than overwriting data the test is asserting on.
 */
trait CompletesDpcProposals
{
    protected function makeProposalSubmittable(DpcProposal $proposal, ?User $actor = null): DpcProposal
    {
        $completeness = app(DpcProposalCompletenessService::class);

        /*
         * **Filled in passes, not in one sweep, and that is the directive's
         * doing.** Before the vacancy rules the sections were independent, so
         * one walk of the checklist was enough. Now they are not: recording the
         * sanctioned strength changes the Zone of Consideration, which changes
         * whether the seniority list is long enough — so a section judged
         * complete at the top of the walk can be incomplete by the bottom of it.
         *
         * Three passes is ample for a two-link chain (vacancy → zone →
         * seniority) and still terminates loudly if a future rule makes the
         * fixture unsatisfiable, which is much easier to diagnose than a test
         * mysteriously receiving 200 where it expected 403.
         */
        for ($pass = 1; $pass <= 3; $pass++) {
            if ($completeness->isComplete($proposal)) {
                return $proposal->refresh();
            }

            $this->fillOnce($proposal, $actor);
            $proposal->refresh();
        }

        if (! $completeness->isComplete($proposal)) {
            /*
             | The *detail* of each outstanding section, not just its name.
             |
             | The gate has several reasons per section since the business directive
             | of 2026-09-02 — an incomplete chart, a quota that contradicts the
             | rule, a breakdown that cannot balance — and a fixture that failed
             | with only "Working Officers & Vacancies (S16)" left the reader to
             | guess which. Diagnosing one of these cost half an hour; printing the
             | reason costs three lines.
             */
            $reasons = collect($completeness->checklist($proposal))
                ->reject(fn (array $section): bool => $section['complete'] === true)
                ->map(fn (array $section): string => $section['label'].' -> '.$section['detail'])
                ->implode(' | ');

            throw new RuntimeException(
                'makeProposalSubmittable() could not satisfy the submission gate: '.$reasons
            );
        }

        return $proposal->refresh();
    }

    /**
     * One pass over the checklist, filling whatever is currently outstanding.
     */
    private function fillOnce(DpcProposal $proposal, ?User $actor): void
    {
        $service = app(DpcProposalService::class);
        $committee = app(DpcCommitteeService::class);
        $completeness = app(DpcProposalCompletenessService::class);

        $actor ??= User::query()->findOrFail($proposal->prepared_by);
        $category = ReservationCategory::query()->orderBy('id')->firstOrFail();

        // Recomputed each pass, against the state the previous pass left.
        $plan = $this->vacancyPlan($proposal);

        foreach ($completeness->checklist($proposal) as $section) {
            if ($section['complete']) {
                continue;
            }

            match ($section['key']) {
                /*
                 | The committee, as the change register of 2026-08-26 shapes it.
                 |
                 | The CGPSC Chairman's seat is **not** listed: `DpcCommitteeService`
                 | places it on every save from the record `Tests\TestCase` seeds, and
                 | a fixture that tried to submit it would have the row discarded.
                 | So this supplies the ordinary members only — the minimum the size
                 | rule asks for besides the Chairman.
                 |
                 | The attributes are all left false. The default configuration
                 | requires no attribute (BD-22), so a compliant committee needs
                 | none, and a fixture that ticked some would be asserting a
                 | composition rule no test here is about.
                 */
                'committee' => $committee->compose($proposal, $this->committeeRows($proposal), $actor),

                /*
                 | Since the change register of 2026-08-27 the sanctioned strength
                 | lives on the **Department Post Mapping** and is no longer an
                 | argument — item 1 took it out of the officer's hands. So the
                 | fixture writes it to the master, chosen so that
                 | `sanctioned − working` equals the vacancy the plan settled on.
                 |
                 | The fixture records no retirement dates and no deputations, so
                 | Total Vacancy equals the Base Vacancy here. A test that cares
                 | about items 3 and 4 sets those dates itself.
                 */
                'working_officers' => $this->recordVacancies($proposal, $plan, $category->id, $service),

                /*
                 | The length is read **live**, after the chart has been saved, and
                 | only falls back to the plan.
                 |
                 | The zone is sized on the Promotion Vacancy since the business
                 | directive of 2026-09-02, which is not the planned total whenever
                 | the Recruitment Rule splits: a 60%-promotion post with three free
                 | chairs offers one promotion seat, and a list sized for three would
                 | miss the zone. Asking the calculator after the fact cannot fall
                 | out of step with it the way a planned number can.
                 */
                'seniority_list' => $service->saveSeniorityEntries(
                    $proposal,
                    $this->seniorityRows(
                        app(VacancyCalculationService::class)->requiredSeniorityCount($proposal->refresh())
                            ?? $plan['seniority'],
                        $category->id,
                    ),
                    $actor,
                ),

                // Since the directive of 2026-08-22 the chart's officers are
                // *projected* from the Seniority List, so the fixture can no
                // longer invent one. It fills evaluation data onto whichever
                // officers the projection produced — which is also why the
                // seniority branch above has to have run first, and why this
                // helper works in passes.
                'evaluation_chart' => $service->saveEvaluationRows(
                    $proposal,
                    $this->evaluationDataFor($proposal),
                    $actor,
                ),

                /*
                 | Rule 1.1. Last in the checklist, so in a pass it runs after the
                 | sections it is confirmed against have been filled; a later pass
                 | re-confirms whatever a section change made stale.
                 */
                'rule_1_1_verification' => $this->confirmRuleOneOne($proposal, $actor),

                default => null,
            };
        }
    }

    /**
     * Record the four Rule 1.1 confirmations a submission needs.
     *
     * The working strength is reconciled as "no previous DPC" — the fixture has
     * none — with the remarks that basis requires.
     */
    protected function confirmRuleOneOne(DpcProposal $proposal, User $actor): void
    {
        $verification = app(ProposalVerificationService::class);

        foreach ($verification->status($proposal->refresh()) as $entry) {
            if ($entry['complete']) {
                continue;
            }

            $verification->confirm(
                $proposal,
                $entry['item'],
                $actor,
                'Fixture: no previous DPC for this post.',
                ['basis' => PreviousDpcBasis::None->value],
            );
        }
    }

    /**
     * Put the sanctioned strength on the master, then save the breakdown.
     *
     * The mapping is superseded rather than edited in the application, but a
     * fixture is not the application: writing the column directly is what keeps
     * this helper about *completeness* rather than about Government Orders.
     */
    private function recordVacancies(
        DpcProposal $proposal,
        array $plan,
        int $categoryId,
        DpcProposalService $service,
    ): void {
        $post = $proposal->promotionToPost;

        if ($post !== null) {
            $post->forceFill(['sanctioned_strength' => $plan['sanctioned']])->save();

            /*
             | The Recruitment Rule became a **required input** with the business
             | directive of 2026-09-02: the promotion vacancy is
             | `promotion chairs − promotion chairs held`, and without a rule there
             | is nothing to say how many of the sanctioned chairs are promotion
             | chairs. A real DPC always has one — it is projected by the Pre-DPC
             | approval it is raised under — but these fixtures build their proposal
             | directly, so they have to register it.
             |
             | Only when the post has none: a test that registered its own share
             | must keep it.
             */
            $hasRule = RecruitmentRule::query()
                ->where('post_id', $post->id)
                ->where('status', MasterStatus::Active->value)
                ->exists();

            if (! $hasRule) {
                RecruitmentRule::query()->create([
                    'post_id' => $post->id,
                    'version' => 1,
                    'government_order_number' => 'GO/COMPLETENESS/1',
                    'direct_recruitment_pct' => 0,
                    'promotion_recruitment_pct' => 100,
                    'effective_from' => '2020-01-01',
                    'status' => MasterStatus::Active->value,
                ]);
            }
        }

        /*
         | Vacancy is stated on the chart since the business directive of
         | 2026-09-02, and the chart must account for **every** sanctioned post:
         | one `vacant` row per vacancy, then an officer holding each remaining
         | chair. The breakdown is derived from them rather than saved beside them.
         |
         | The officers come by `promotion`, which is the only mode a
         | 100%-promotion rule allows — and 100% is what these fixtures register,
         | so any other value would contradict the master they just wrote.
         */
        $chart = [];
        $vacancy = (int) $plan['vacancy'];

        for ($i = 0; $i < $vacancy; $i++) {
            $chart[] = [
                'sn' => count($chart) + 1,
                'status' => DpcWorkingOfficer::STATUS_VACANT,
                'reservation_category_id' => $categoryId,
            ];
        }

        for ($i = $vacancy; $i < (int) $plan['sanctioned']; $i++) {
            $chart[] = [
                'sn' => count($chart) + 1,
                'status' => DpcWorkingOfficer::STATUS_WORKING,
                'source' => DpcWorkingOfficer::SOURCE_PROMOTION,
                'name' => 'Incumbent '.(count($chart) + 1),
                'dob' => '1975-01-01',
                'reservation_category_id' => $categoryId,
            ];
        }

        $service->saveWorkingOfficers($proposal->refresh(), $chart);
        $service->saveVacancies($proposal->refresh());
    }

    /**
     * A vacancy, a sanctioned strength and a seniority-list length that satisfy
     * all three invariants together.
     *
     * **The seniority list drives, not the vacancy.** If a test has already
     * written its own list, overwriting it would destroy the thing the test is
     * asserting on, so instead the vacancy is chosen to make the zone equal that
     * length. Where no vacancy can (the zone is `single_vacancy_size` at 1
     * vacancy and `2V + 4` above it, so 5, 8, 10, 12 … are reachable and 1, 2, 3
     * are not) the helper falls back to its own consistent set and the test's
     * list is replaced — which is correct, because such a list could never have
     * been submitted anyway.
     *
     * @return array{sanctioned: int, vacancy: int, seniority: int}
     */
    private function vacancyPlan(DpcProposal $proposal): array
    {
        $calculator = app(VacancyCalculationService::class);
        $working = $proposal->workingOfficers()->count();
        $existingSeniority = $proposal->seniorityEntries()->count();

        /*
         | **If the vacancy section is already complete, work from it.**
         |
         | `fillOnce()` fills only the sections that are outstanding, so a test that
         | saved its own vacancy breakdown before calling this helper will have
         | `working_officers` skipped — and a plan that assumed its own vacancy would
         | then size the seniority list against a vacancy nobody imposed. The list
         | would never match the zone and the helper would give up reporting
         | "Seniority List" outstanding, which is a confusing way to say "your
         | vacancy and my vacancy disagree".
         |
         | So when the section is complete the existing total is authoritative and
         | the seniority requirement is read from it.
         */
        if (app(DpcProposalCompletenessService::class)->sectionIsComplete($proposal, 'working_officers')) {
            $vacancy = $calculator->breakdown($proposal)->totalVacancy ?? 0;

            return [
                'sanctioned' => $proposal->promotionToPost?->sanctioned_strength ?? $working + $vacancy,
                'vacancy' => $vacancy,
                'seniority' => $calculator->requiredSeniorityCount($proposal, $vacancy) ?? max(1, $existingSeniority),
            ];
        }

        if ($existingSeniority > 0) {
            $vacancy = $this->vacancyGivingZoneOf($proposal, $existingSeniority);

            if ($vacancy !== null) {
                return [
                    'sanctioned' => $working + $vacancy,
                    'vacancy' => $vacancy,
                    'seniority' => $existingSeniority,
                ];
            }
        }

        // Default: one vacancy, which the deck sizes at `single_vacancy_size`
        // (5) rather than at `2 × 1 + 4`. Deliberately the awkward case, so the
        // shared fixture exercises the rule most likely to be got wrong.
        $vacancy = 1;
        $required = $calculator->requiredSeniorityCount($proposal, $vacancy);

        return [
            'sanctioned' => $working + $vacancy,
            'vacancy' => $vacancy,
            // Null means this class pair has no zone formula, so any length is
            // acceptable and one row keeps the fixture small.
            'seniority' => $required ?? 1,
        ];
    }

    /**
     * The vacancy count whose zone equals `$target`, or null if none does.
     *
     * Searched rather than inverted algebraically: the formula lives in
     * configuration and has a special case at one vacancy, so asking the engine
     * cannot fall out of step with it the way a re-derived inverse would.
     */
    private function vacancyGivingZoneOf(DpcProposal $proposal, int $target): ?int
    {
        $calculator = app(VacancyCalculationService::class);

        for ($vacancy = 1; $vacancy <= $target + 1; $vacancy++) {
            if ($calculator->requiredSeniorityCount($proposal, $vacancy) === $target) {
                return $vacancy;
            }
        }

        return null;
    }

    /**
     * Minimal evaluation data for every officer the Seniority List projected.
     *
     * The full mandatory window with a top grade throughout, and both flags on every
     * year — enough to make the section complete without expressing any opinion about
     * eligibility, so a test that cares about the workflow is not accidentally
     * asserting a scoring outcome.
     *
     * **Why the full window rather than one year.** This was a single 2017 row until
     * the change register of 2026-08-27 added the merit gates: a Property Return
     * missing from the mandatory continuous window now *rejects* the candidate
     * (item 13), and CRs short of the mandatory count without a justification are a
     * rule failure (item 14). One year would leave every officer in every workflow
     * test gated out before the formula ran — which is an opinion about eligibility,
     * and the loudest one available.
     *
     * The window is read from configuration rather than hardcoded so it follows
     * `dpc.merit.*`; a fixture that silently disagreed with the rule in force would
     * fail on the gate and read as a workflow bug.
     *
     * @return list<array<string, mixed>>
     */
    private function evaluationDataFor(DpcProposal $proposal): array
    {
        $years = $this->mandatoryRecordYears();

        return $proposal->evaluationRows()->orderBy('sn')->get()
            ->map(fn ($row): array => [
                'sn' => $row->sn,
                'cr_grades' => array_map(
                    static fn (int $year): array => ['year' => $year, 'grade' => 'क'],
                    $years,
                ),
                'loyalty_flags' => array_map(
                    static fn (int $year): array => ['year' => $year, 'flag' => true],
                    $years,
                ),
                'property_flags' => array_map(
                    static fn (int $year): array => ['year' => $year, 'flag' => true],
                    $years,
                ),
            ])
            ->all();
    }

    /**
     * The years a complete, ungated chart has to carry.
     *
     * Ends at 2017 because that is the year the rest of the fixtures and the golden
     * tests use, and runs back far enough to satisfy whichever of the two windows is
     * longer — the CR count need not be continuous (item 14) but the Property Return
     * must be (item 13), so one continuous run satisfies both.
     *
     * @return list<int>
     */
    private function mandatoryRecordYears(): array
    {
        $span = max(
            (int) config('dpc.merit.required_cr_count', 5),
            (int) config('dpc.merit.property_return_years', 5),
            1,
        );

        return range(2017 - $span + 1, 2017);
    }

    /**
     * A committee that satisfies the rules in force — the size **and** the required
     * composition.
     *
     * The size is read from `CommitteeRuleResolver` rather than hardcoded at three:
     * the bound comes from the Committee Configuration master where one is
     * registered, and a fixture with a literal `3` would start failing the day a
     * demo seeder changed it — with a message about submission, not about the
     * committee.
     *
     * **The attributes are read the same way**, from the same rules. Business made
     * the composition mandatory on 2026-09-06 — one member from each of SC, ST and
     * OBC, plus Female, Bhar Sadhak Sachiv and Vibhag Sachiv — and made an
     * incomplete committee block submission, so a fixture seating bare members would
     * stop every test that submits a proposal, none of which is about the committee.
     *
     * Distributed the way the rules force: the mutually exclusive social categories
     * take one seat each, and everything freely combinable goes on the last seat.
     * A committee smaller than the number of required categories cannot cover them,
     * and no arrangement here would change that — that is a configuration the rules
     * themselves refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function committeeRows(DpcProposal $proposal): array
    {
        $rules = app(CommitteeRuleResolver::class)->forProposal($proposal);
        $size = max(1, $rules->minMembers);

        $exclusive = array_values(array_filter(
            $rules->requiredCategories,
            static fn (MemberCategory $category): bool => $category->isSocialCategory(),
        ));

        $combinable = array_values(array_filter(
            $rules->requiredCategories,
            static fn (MemberCategory $category): bool => ! $category->isSocialCategory(),
        ));

        $rows = [];

        for ($i = 1; $i <= $size; $i++) {
            $attributes = [];

            if (isset($exclusive[$i - 1])) {
                $attributes[$exclusive[$i - 1]->column()] = true;
            }

            if ($i === $size) {
                foreach ($combinable as $category) {
                    $attributes[$category->column()] = true;
                }
            }

            $rows[] = $attributes + [
                'name' => 'Committee Member '.$i,
                'designation' => 'Secretary',
                'capacity' => 'member',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seniorityRows(int $count, int $categoryId): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'seniority_number' => $i,
                'name' => 'Seniority Officer '.$i,
                'dob' => '1970-01-01',
                'reservation_category_id' => $categoryId,
            ];
        }

        return $rows;
    }
}
