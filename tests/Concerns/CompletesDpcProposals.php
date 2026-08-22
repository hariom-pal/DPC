<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
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
            throw new RuntimeException(
                'makeProposalSubmittable() could not satisfy the submission gate. Outstanding: '
                .implode('; ', $completeness->missingSections($proposal))
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
                'committee' => $service->saveCommittee($proposal, [
                    ['name' => 'Committee Chairman', 'designation' => 'Secretary', 'capacity' => 'chairman'],
                ], $actor),

                // Sanctioned strength chosen so that `sanctioned − working`
                // equals the vacancy the plan settled on, whatever the officer
                // chart already holds.
                'working_officers' => $service->saveVacancies(
                    $proposal,
                    $plan['sanctioned'],
                    [[
                        'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
                        'reservation_category_id' => $category->id,
                        'count' => $plan['vacancy'],
                    ]],
                ),

                'seniority_list' => $service->saveSeniorityEntries(
                    $proposal,
                    $this->seniorityRows($plan['seniority'], $category->id),
                    $actor,
                ),

                'evaluation_chart' => $service->saveEvaluationRows($proposal, [[
                    'sn' => 1,
                    'name' => 'Evaluated Officer',
                    'dob' => '1970-01-01',
                    'reservation_category_id' => $category->id,
                    'cr_grades' => [['year' => 2017, 'grade' => 'क']],
                    'loyalty_flags' => [['year' => 2017, 'flag' => true]],
                    'property_flags' => [['year' => 2017, 'flag' => true]],
                ]], $actor),

                default => null,
            };
        }
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
