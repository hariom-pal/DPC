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
 * Completeness first, and — since the business directive of 2026-08-22 — three
 * arithmetic invariants as well.
 *
 * **That is a deliberate narrowing of an earlier ruling, and worth stating
 * plainly.** This class used to say it would never score, rank, size a zone or
 * judge eligibility, because those are M5's and a proposal with unusual-looking
 * data must still be submittable. The directive changes that for exactly three
 * checks, and no others:
 *
 *   - working officers must not exceed the sanctioned strength;
 *   - the vacancy classification must sum to `sanctioned − working`;
 *   - the seniority list must carry exactly as many officers as the zone admits.
 *
 * Nothing here still scores or ranks an officer, and no eligibility verdict is
 * reached. The zone is not *computed* here either — it is asked of the M5
 * engine through {@see VacancyCalculationService}, so there remains exactly one
 * zone implementation (AD-19).
 *
 * **Why these belong at the submission gate and not only in the Form
 * Requests.** The Excel import writes sections without passing through a
 * request, and a proposal can be left in a broken state by editing one section
 * after another was saved — lower the sanctioned strength after importing the
 * officer chart and the ceiling is breached by a screen that never validated
 * it. The gate is the one place every route must pass through before an
 * Administrative Head is asked to sign.
 *
 * Working officers are not required: the target post may legitimately be
 * vacant, which is often precisely why a DPC is being held. The vacancy
 * breakdown is required, because a DPC with no vacancy has nothing to fill.
 */
class DpcProposalCompletenessService
{
    public function __construct(
        private readonly VacancyCalculationService $vacancies,
        private readonly EvaluationCandidateService $candidates,
    ) {}

    /**
     * One entry per section the officer must finish, in PPT screen order.
     *
     * @return list<array{key: string, label: string, screen: string, route: string, complete: bool, detail: string}>
     */
    public function checklist(DpcProposal $proposal): array
    {
        $proposal->loadCount(['committeeMembers', 'vacancies', 'workingOfficers', 'seniorityEntries', 'evaluationRows']);

        $vacancyTotal = (int) $proposal->vacancies()->sum('count');
        $sums = $this->vacancies->summary($proposal);
        $chart = $this->candidates->summary($proposal);

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
                'complete' => $proposal->sanctioned_post_count !== null
                    && $vacancyTotal > 0
                    && ! $this->ceilingBreached($sums)
                    && $this->classificationBalances($sums),
                'detail' => match (true) {
                    $proposal->sanctioned_post_count === null => 'Sanctioned post count not entered.',
                    $this->ceilingBreached($sums) => 'Working Officers cannot exceed the sanctioned strength.'
                        ." Sanctioned {$sums['sanctioned']}, {$sums['working']} officer(s) working.",
                    $vacancyTotal < 1 => 'No vacancies recorded — a DPC needs at least one vacancy to fill.',
                    ! $this->classificationBalances($sums) => 'The sum of all vacancy types must equal the calculated total vacancy.'
                        ." Total vacancy {$sums['total_vacancy']}; the breakdown totals {$sums['classified_total']}.",
                    default => "Sanctioned {$sums['sanctioned']}, {$sums['working']} working, "
                        ."{$sums['total_vacancy']} vacancy/vacancies classified across "
                        .$proposal->vacancies_count.' row(s)',
                },
            ],
            [
                'key' => 'seniority_list',
                'label' => 'Seniority List',
                'screen' => 'S17',
                'route' => 'dpc-proposals.seniority-list',
                'complete' => $proposal->seniority_entries_count > 0 && $this->seniorityMatchesZone($sums),
                'detail' => match (true) {
                    $proposal->seniority_entries_count < 1 => 'No seniority entries yet.',
                    ! $this->seniorityMatchesZone($sums) => $this->vacancies->seniorityCountMessage((int) $sums['required_seniority'])
                        ." The list currently carries {$sums['seniority_entered']}.",
                    $sums['required_seniority'] !== null => $proposal->seniority_entries_count
                        .' officer(s), matching the Zone of Consideration of '.$sums['required_seniority'],
                    default => $proposal->seniority_entries_count.' officer(s) in the feeder seniority list',
                },
            ],
            [
                'key' => 'evaluation_chart',
                'label' => 'Evaluation / Tabulation Chart',
                'screen' => 'S18',
                'route' => 'dpc-proposals.evaluation-chart',
                // Since 2026-08-22 the chart's officers are projected from the
                // Seniority List, so "complete" means the two agree — not merely
                // that some rows exist. A chart that has drifted from the list it
                // was projected from is the one state that must never reach an
                // Administrative Head for signature.
                'complete' => $chart['matches'],
                'detail' => match (true) {
                    ! $chart['has_candidates'] => 'Upload the Seniority List first — the chart is generated from it.',
                    $chart['present'] < 1 => 'The chart has no officers yet, though '.$chart['expected'].' are expected.',
                    ! $chart['matches'] => $this->candidates->mismatchMessage()
                        ." Expected {$chart['expected']}, present {$chart['present']}.",
                    default => $chart['present'].' officer(s) on the chart, matching the Seniority List',
                },
            ],
        ];
    }

    public function isComplete(DpcProposal $proposal): bool
    {
        return $this->missingSections($proposal) === [];
    }

    /**
     * Each invariant is read through a named method rather than inline, so the
     * `complete` flag and the `detail` string cannot disagree about why a
     * section is not finished — they ask the same question.
     *
     * Each also honours its own configuration switch: standing a rule down must
     * stand it down at the submission gate too, or the officer is refused at a
     * gate whose rule has been turned off everywhere else.
     *
     * @param  array<string, mixed>  $sums
     */
    private function ceilingBreached(array $sums): bool
    {
        return (bool) config('dpc.vacancy.enforce_sanctioned_ceiling', true)
            && $sums['exceeds_sanctioned'] === true;
    }

    /**
     * @param  array<string, mixed>  $sums
     */
    private function classificationBalances(array $sums): bool
    {
        if (! (bool) config('dpc.vacancy.enforce_classification_balance', true)) {
            return true;
        }

        // Unknowable while the sanctioned strength is absent; that case is
        // reported by the sanctioned-count check instead of twice.
        return $sums['total_vacancy'] === null || $sums['balanced'] === true;
    }

    /**
     * @param  array<string, mixed>  $sums
     */
    private function seniorityMatchesZone(array $sums): bool
    {
        if (! (bool) config('dpc.vacancy.enforce_seniority_matches_zone', true)) {
            return true;
        }

        // Null means no exact count can be required — an unentered sanctioned
        // strength, a class pair the deck gives no formula for, or no vacancy.
        return $sums['required_seniority'] === null
            || $sums['seniority_entered'] === $sums['required_seniority'];
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
