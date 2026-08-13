<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcProposal;

/**
 * Is this proposal complete enough to be submitted for approval? — M4 Phase 7
 * (final workflow validation).
 *
 * Until this existed, an empty proposal could be submitted and an
 * Administrative Head could be asked to sign a document with no committee, no
 * officers, and no evaluation chart. The PPT's own flow (S14 → S18, each
 * screen ending in SAVE AND NEXT) is a sequence that must be finished before
 * S19's approval, and this service is that sequence expressed as a check.
 *
 * Deliberately *completeness* only, never correctness: nothing here scores,
 * ranks, sizes a zone, or judges eligibility. Those are M5's, and a proposal
 * with unusual-looking data must still be submittable — the Commission
 * decides, not this system (architect directive: no calculations in M4).
 *
 * Working officers are not required: the target post may legitimately be
 * vacant, which is often precisely why a DPC is being held. The vacancy
 * breakdown is required, because a DPC with no vacancy has nothing to fill.
 */
class DpcProposalCompletenessService
{
    /**
     * One entry per section the officer must finish, in PPT screen order.
     *
     * @return list<array{key: string, label: string, screen: string, route: string, complete: bool, detail: string}>
     */
    public function checklist(DpcProposal $proposal): array
    {
        $proposal->loadCount(['committeeMembers', 'vacancies', 'workingOfficers', 'seniorityEntries', 'evaluationRows']);

        $vacancyTotal = (int) $proposal->vacancies()->sum('count');

        return [
            [
                'key' => 'post_selection',
                'label' => 'Selection of Posts',
                'screen' => 'S14',
                'route' => 'dpc-proposals.edit',
                'complete' => true,
                'detail' => $proposal->postFromCadre->name.' → '.$proposal->postToCadre->name,
            ],
            [
                'key' => 'committee',
                'label' => 'DPC Committee',
                'screen' => 'S15',
                'route' => 'dpc-proposals.committee',
                'complete' => $proposal->committee_members_count > 0,
                'detail' => $proposal->committee_members_count > 0
                    ? $proposal->committee_members_count.' member(s) named'
                    : 'No committee members named yet.',
            ],
            [
                'key' => 'working_officers',
                'label' => 'Working Officers & Vacancies',
                'screen' => 'S16',
                'route' => 'dpc-proposals.working-officers',
                'complete' => $proposal->sanctioned_post_count !== null && $vacancyTotal > 0,
                'detail' => match (true) {
                    $proposal->sanctioned_post_count === null => 'Sanctioned post count not entered.',
                    $vacancyTotal < 1 => 'No vacancies recorded — a DPC needs at least one vacancy to fill.',
                    default => "Sanctioned {$proposal->sanctioned_post_count}, {$vacancyTotal} vacancy/vacancies, {$proposal->working_officers_count} officer(s) working",
                },
            ],
            [
                'key' => 'seniority_list',
                'label' => 'Seniority List',
                'screen' => 'S17',
                'route' => 'dpc-proposals.seniority-list',
                'complete' => $proposal->seniority_entries_count > 0,
                'detail' => $proposal->seniority_entries_count > 0
                    ? $proposal->seniority_entries_count.' officer(s) in the feeder seniority list'
                    : 'No seniority entries yet.',
            ],
            [
                'key' => 'evaluation_chart',
                'label' => 'Evaluation / Tabulation Chart',
                'screen' => 'S18',
                'route' => 'dpc-proposals.evaluation-chart',
                'complete' => $proposal->evaluation_rows_count > 0,
                'detail' => $proposal->evaluation_rows_count > 0
                    ? $proposal->evaluation_rows_count.' officer(s) on the chart'
                    : 'No evaluation chart entries yet.',
            ],
        ];
    }

    public function isComplete(DpcProposal $proposal): bool
    {
        return $this->missingSections($proposal) === [];
    }

    /**
     * Human-readable labels of the sections still outstanding.
     *
     * @return list<string>
     */
    public function missingSections(DpcProposal $proposal): array
    {
        $missing = [];

        foreach ($this->checklist($proposal) as $section) {
            if (! $section['complete']) {
                $missing[] = $section['label'].' ('.$section['screen'].')';
            }
        }

        return $missing;
    }
}
