<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Committee\Services\CommitteeComplianceService;
use App\Domain\Dpc\Models\DpcCommitteeMember;
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
        private readonly CommitteeComplianceService $committee,
        private readonly ProposalVerificationService $verification,
        private readonly VacancyMasterService $vacancyMaster,
        private readonly EmployeeDetailsService $employeeDetails,
    ) {}

    /**
     * One entry per section the officer must finish, in PPT screen order.
     *
     * @return list<array{key: string, label: string, screen: string, route: string, complete: bool, detail: string}>
     */
    /**
     * Whether one named section is complete.
     *
     * A convenience over {@see checklist()} for callers that care about a single
     * section — the submission gate wants the whole list, but a caller deciding
     * *whether to fill* a section only needs the one answer, and rebuilding the
     * whole checklist by hand at each such call site is how the two come to
     * disagree about what "complete" means.
     */
    public function sectionIsComplete(DpcProposal $proposal, string $key): bool
    {
        foreach ($this->checklist($proposal) as $section) {
            if ($section['key'] === $key) {
                return (bool) $section['complete'];
            }
        }

        return false;
    }

    /**
     * Everything the *saved* committee fails, judged by the same service the save
     * uses.
     *
     * Read from the database rather than from a payload, because this asks about a
     * committee that already exists. The rows are handed over as arrays for the
     * same reason `CommitteeComplianceService` takes arrays at all: it judges a
     * committee, and does not care whether it came from a form or a table.
     *
     * @return list<string>
     */
    private function committeeFindings(DpcProposal $proposal): array
    {
        if ($proposal->committee_members_count === 0) {
            return [];
        }

        $members = $proposal->committeeMembers()
            ->get()
            ->map(static fn (DpcCommitteeMember $member): array => array_merge(
                $member->only(DpcCommitteeMember::ATTRIBUTE_COLUMNS),
                [
                    'name' => $member->name,
                    'capacity' => $member->capacity->value,
                    'is_dpc_chairman' => $member->is_dpc_chairman,
                ],
            ))
            ->all();

        return $this->committee->findings($proposal, $members);
    }

    private function committeeDetail(DpcProposal $proposal): string
    {
        if ($proposal->committee_members_count === 0) {
            return 'No committee members named yet.';
        }

        $findings = $this->committeeFindings($proposal);

        if ($findings === []) {
            return $proposal->committee_members_count.' member(s) named';
        }

        return $proposal->committee_members_count.' member(s) named — '.implode(' ', $findings);
    }

    /**
     * @return list<array<string, bool|string>>
     */
    public function checklist(DpcProposal $proposal): array
    {
        $proposal->loadCount(['committeeMembers', 'vacancies', 'workingOfficers', 'seniorityEntries', 'evaluationRows']);

        $vacancyTotal = (int) $proposal->vacancies()->sum('count');
        $sums = $this->vacancies->summary($proposal);
        $chart = $this->candidates->summary($proposal);

        $sections = [
            [
                'key' => 'post_selection',
                'label' => 'Selection of Posts',
                'screen' => 'S14',
                'route' => 'dpc-proposals.edit',
                'complete' => true,
                'detail' => $proposal->promotionFromPost->name.' → '.$proposal->promotionToPost->name,
            ],
            /*
             | **Complete means compliant, not merely populated** (business
             | instruction, 2026-09-06: *"until all of this is there, keep showing
             | the committee as incomplete"*).
             |
             | It counted members until then, so a committee of three carrying none
             | of the required attributes reported as done — and the officer met the
             | real rule only when Save refused it, several screens later. The check
             | is the same `CommitteeComplianceService` the Form Request runs, so
             | the checklist and the save cannot disagree.
             |
             | The detail line names what is missing rather than saying "not
             | compliant": a checklist entry that states the gap is one the officer
             | can act on without opening the screen.
             */
            [
                'key' => 'committee',
                'label' => 'DPC Committee',
                'screen' => 'S15',
                'route' => 'dpc-proposals.committee',
                'complete' => $proposal->committee_members_count > 0 && $this->committeeFindings($proposal) === [],
                'detail' => $this->committeeDetail($proposal),
            ],
            [
                'key' => 'working_officers',
                'label' => 'Working Officers & Vacancies',
                'screen' => 'S16',
                'route' => 'dpc-proposals.working-officers',
                /*
                 | Read from the live summary, not from `sanctioned_post_count`.
                 |
                 | Since the change register of 2026-08-27 the strength lives on the
                 | Department Post Mapping and the column on the proposal is a
                 | snapshot taken when the vacancy was last calculated. The gate has
                 | to judge the master: a mapping superseded after the chart was
                 | saved changes the vacancy, and a check reading the stale snapshot
                 | would let an out-of-date proposal through to signature.
                 */
                'complete' => $sums['sanctioned'] !== null
                    && $vacancyTotal > 0
                    && ! $this->ceilingBreached($sums)
                    && $sums['chart_incomplete'] !== true
                    && $sums['quota_overfilled'] !== true
                    && $this->classificationBalances($sums),
                'detail' => match (true) {
                    $sums['sanctioned'] === null => 'The Department Post Mapping for this post records no Sanctioned Strength.',
                    $this->ceilingBreached($sums) => 'Working Officers cannot exceed the sanctioned strength.'
                        ." Sanctioned {$sums['sanctioned']}, {$sums['working']} officer(s) working.",
                    $sums['chart_incomplete'] === true => 'The chart does not account for every sanctioned post.'
                        ." Sanctioned {$sums['sanctioned']}, {$sums['breakdown']->chartRows} row(s) on the chart."
                        .' Add a row for each remaining post — an officer if one holds it, a vacant post if not.',
                    $sums['quota_overfilled'] === true => 'The chart contradicts the Recruitment Rule: more posts are'
                        ." recorded against one mode of appointment than {$sums['breakdown']->promotionPercentage}%"
                        .' promotion allows. Correct either the rows or the rule.',
                    $vacancyTotal < 1 => 'No vacancies recorded — a DPC needs at least one vacancy to fill.',
                    /*
                     | Since the business directive of 2026-09-02 the breakdown is
                     | derived from the chart, so this can no longer be an officer's
                     | data-entry mistake — the two figures come from one source and
                     | agree by construction. It survives as a guard on the
                     | `vacancy_types` master: a missing type code is the one way the
                     | derivation can drop a row, and silently promoting against a
                     | short breakdown is worse than stopping.
                     */
                    ! $this->classificationBalances($sums) => 'The derived vacancy breakdown does not match the Total Vacancy'
                        .", which means a Vacancy Type master row is missing. Total vacancy {$sums['total_vacancy']}; the breakdown totals {$sums['classified_total']}."
                        .' Ask the Administrator to restore the Vacancy Types master.',
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
                'complete' => $proposal->seniority_entries_count > 0 && $this->seniorityMatchesZone($sums)
                    && $this->seniorityAsOnProblem($proposal) === null,
                'detail' => match (true) {
                    $proposal->seniority_entries_count < 1 => 'No seniority entries yet.',
                    ! $this->seniorityMatchesZone($sums) => $this->vacancies->seniorityCountMessage((int) $sums['required_seniority'])
                        ." The list currently carries {$sums['seniority_entered']}.",
                    $this->seniorityAsOnProblem($proposal) !== null => (string) $this->seniorityAsOnProblem($proposal),
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

        /*
         | Employee-wise details (Coder Instructions, points 2 and 3, rule 4.1):
         | every officer's CR, Property Return, PH questionnaire and documents.
         | Part of this list, so `submit()` refuses through the gate it already
         | applies; its own switch stands it down at submission too.
         */
        if ((bool) config('dpc.employee_details.required_for_submission', true)) {
            $sections[] = $this->employeeDetailsSection($proposal);
        }

        /*
         | The Vacancy Master — the CGPSC Administrator's finalized year-wise
         | position for this post. Present only when one exists (or when the
         | setting makes it mandatory), so proposals of posts the Administrator
         | has not yet recorded keep working as before. A draft position is never
         | read here: only a finalized one is authoritative.
         */
        $vacancyMaster = $this->vacancyMasterSection($proposal, $sums);

        if ($vacancyMaster !== null) {
            $sections[] = $vacancyMaster;
        }

        /*
         | Rule 1.1 — the department's confirmations, last, because they are made
         | against the figures the sections above produce. Part of this list, not a
         | separate gate, so `submit()` refuses an unverified proposal through the
         | same check it already applies and the screen shows it in the same place.
         */
        if ($this->verification->enforced()) {
            $outstanding = $this->verification->outstanding($proposal);

            $sections[] = [
                'key' => 'rule_1_1_verification',
                'label' => __('dpc.verification.section_label'),
                'screen' => __('dpc.verification.section_screen'),
                'route' => 'dpc-proposals.verification',
                'complete' => $outstanding === [],
                'detail' => $outstanding === []
                    ? __('dpc.verification.all_confirmed')
                    : __('dpc.verification.outstanding', ['items' => implode('; ', $outstanding)]),
            ];
        }

        return $sections;
    }

    /**
     * The proposal's chart reconciled against the finalized Vacancy Master.
     *
     * Two facts must agree: the sanctioned strength, and the officers working on
     * the post (rule 4.4 — the count must match the list one hundred per cent).
     * The promotion vacancy is reported beside them, not required to match: a
     * Vacancy Master may count a vacancy (a higher-post promotion) that a Working
     * Officers chart has no way to express.
     *
     * @param  array<string, mixed>  $sums
     * @return array<string, bool|string>|null
     */
    private function vacancyMasterSection(DpcProposal $proposal, array $sums): ?array
    {
        $position = $this->vacancyMaster->finalizedForProposal($proposal);
        $required = (bool) config('dpc.vacancy_master.required_for_submission', false);

        if ($position === null && ! $required) {
            return null;
        }

        $section = [
            'key' => 'vacancy_master',
            'label' => __('vacancymaster.proposal.section_label'),
            'screen' => __('vacancymaster.proposal.section_screen'),
            'route' => 'dpc-proposals.working-officers',
        ];

        if ($position === null) {
            return $section + ['complete' => false, 'detail' => __('vacancymaster.proposal.not_found_required')];
        }

        $masterWorking = $position->breakdown['working']['total'] ?? null;
        $problems = [];

        if ($sums['sanctioned'] !== $position->sanctioned_strength) {
            $problems[] = __('vacancymaster.proposal.sanctioned_mismatch', [
                'proposal' => $sums['sanctioned'] ?? '—',
                'master' => $position->sanctioned_strength ?? '—',
            ]);
        }

        if ($sums['working'] !== $masterWorking) {
            $problems[] = __('vacancymaster.proposal.working_mismatch', [
                'proposal' => $sums['working'],
                'master' => $masterWorking ?? '—',
            ]);
        }

        return $section + [
            'complete' => $problems === [],
            'detail' => $problems === []
                ? __('vacancymaster.proposal.matches', ['revision' => $position->revision, 'promotion' => $position->promotion_vacancy])
                : implode(' ', $problems),
        ];
    }

    /**
     * Rule 1.6 at the submission gate: the list must have been stated as drawn up on
     * 1 April of the year before the proposal's *current* DPC year — a list saved
     * before the DPC year was edited is reported, not accepted.
     */
    private function seniorityAsOnProblem(DpcProposal $proposal): ?string
    {
        if (! (bool) config('dpc.seniority.require_as_on_date', true)) {
            return null;
        }

        $required = $proposal->seniorityReferenceDate();

        if ($proposal->seniority_list_as_on === null) {
            return (string) __('dpc.seniority_list.as_on_not_recorded', ['date' => $required->format('d-m-Y')]);
        }

        if (! $proposal->seniority_list_as_on->isSameDay($required)) {
            return (string) __('dpc.seniority_list.as_on_stale', [
                'stated' => $proposal->seniority_list_as_on->format('d-m-Y'),
                'year' => $proposal->dpc_year ?: $proposal->proposal_date->year,
                'date' => $required->format('d-m-Y'),
            ]);
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, screen: string, route: string, complete: bool, detail: string}
     */
    private function employeeDetailsSection(DpcProposal $proposal): array
    {
        $statuses = $this->employeeDetails->statuses($proposal);
        $pending = $statuses->reject(fn (array $entry): bool => $entry['status']['complete']);

        $detail = match (true) {
            $statuses->isEmpty() => __('dpc.employee_details.completeness.no_officers'),
            $pending->isEmpty() => __('dpc.employee_details.completeness.all_complete', ['count' => $statuses->count()]),
            default => __('dpc.employee_details.completeness.incomplete', [
                'pending' => $pending->count(),
                'count' => $statuses->count(),
                'officers' => $pending->take(5)
                    ->map(fn (array $entry): string => "{$entry['row']->sn}. {$entry['row']->name} ({$entry['status']['summary']})")
                    ->implode('; ').($pending->count() > 5 ? ' …' : ''),
            ]),
        };

        return [
            'key' => 'employee_details',
            'label' => (string) __('dpc.employee_details.completeness.label'),
            'screen' => (string) __('dpc.employee_details.completeness.screen'),
            'route' => 'dpc-proposals.employees.index',
            'complete' => $statuses->isNotEmpty() && $pending->isEmpty(),
            'detail' => (string) $detail,
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
