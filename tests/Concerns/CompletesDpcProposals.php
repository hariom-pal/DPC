<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;

/**
 * Brings a draft proposal up to the completeness the S14→S18 sequence requires
 * before it can be submitted (M4 Phase 7, final workflow validation).
 *
 * Only sections that are actually incomplete are filled, so a test that has
 * already populated the section it cares about keeps its own data — the helper
 * supplies the surrounding sections that the submit gate now insists on.
 */
trait CompletesDpcProposals
{
    protected function makeProposalSubmittable(DpcProposal $proposal, ?User $actor = null): DpcProposal
    {
        $service = app(DpcProposalService::class);
        $completeness = app(DpcProposalCompletenessService::class);

        $actor ??= User::query()->findOrFail($proposal->prepared_by);
        $category = ReservationCategory::query()->orderBy('id')->firstOrFail();

        foreach ($completeness->checklist($proposal) as $section) {
            if ($section['complete']) {
                continue;
            }

            match ($section['key']) {
                'committee' => $service->saveCommittee($proposal, [
                    ['name' => 'Committee Chairman', 'designation' => 'Secretary', 'capacity' => 'chairman'],
                ], $actor),

                'working_officers' => $service->saveVacancies($proposal, 4, [[
                    'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
                    'reservation_category_id' => $category->id,
                    'count' => 1,
                ]]),

                'seniority_list' => $service->saveSeniorityEntries($proposal, [[
                    'seniority_number' => 1,
                    'name' => 'Seniority Officer',
                    'dob' => '1970-01-01',
                    'reservation_category_id' => $category->id,
                ]], $actor),

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

        return $proposal->refresh();
    }
}
