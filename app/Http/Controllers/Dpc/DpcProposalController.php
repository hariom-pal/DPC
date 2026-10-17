<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Committee\Services\CommitteeMemberDirectory;
use App\Domain\Committee\Services\CommitteeRuleResolver;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\ReturnDpcProposalRequest;
use App\Domain\Dpc\Requests\SaveDpcCommitteeRequest;
use App\Domain\Dpc\Requests\SaveDpcProposalRequest;
use App\Domain\Dpc\Requests\SaveEvaluationChartRequest;
use App\Domain\Dpc\Requests\SaveSeniorityEntriesRequest;
use App\Domain\Dpc\Requests\SaveWorkingOfficersRequest;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EvaluationCandidateService;
use App\Domain\Dpc\Services\ProposalVacancyPosition;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Import\Services\ImportTemplateBuilder;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\UserManagement\Services\PromotionCandidateProvisioner;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use RuntimeException;

class DpcProposalController extends Controller
{
    /**
     * PPT S21–S25: the Administrative Head reviews the same five sections the
     * officer filled in, one screen at a time. The order and screen references
     * live here so the walkthrough and the readiness checklist cannot drift.
     *
     * @var list<array{label: string, screen: string, partial: string}>
     */
    private const REVIEW_STEPS = [
        ['label' => 'Selection of Posts', 'screen' => 'S21', 'partial' => 'post-selection'],
        ['label' => 'DPC Committee', 'screen' => 'S22', 'partial' => 'committee'],
        ['label' => 'Officers Under Consideration', 'screen' => 'S23', 'partial' => 'working-officers'],
        ['label' => 'Seniority List', 'screen' => 'S24', 'partial' => 'seniority'],
        ['label' => 'Tabulation Chart', 'screen' => 'S25', 'partial' => 'evaluation'],
    ];

    public function __construct(
        private readonly DpcProposalService $service,
        private readonly DpcProposalCompletenessService $completeness,
        private readonly VacancyCalculationService $vacancies,
        private readonly EvaluationCandidateService $candidates,
        private readonly ImportTemplateBuilder $templates,
        private readonly DpcCommitteeService $committee,
        private readonly CommitteeRuleResolver $committeeRules,
        private readonly CommitteeMemberDirectory $directory,
        private readonly CgpscChairmanService $chairmen,
        private readonly PromotionCandidateProvisioner $candidateAccounts,
        private readonly ProposalVerificationService $verification,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcProposal::class);

        return view('dpc-proposals.index', [
            'proposals' => DpcProposal::query()
                ->where('department_id', $this->departmentId($request))
                ->with('dpcType', 'promotionToPost', 'promotionFromPost')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', DpcProposal::class);

        return view('dpc-proposals.form', $this->formData($request, new DpcProposal));
    }

    /**
     * The approved Pre-DPCs this department may raise a DPC from (change item 11).
     *
     * Deliberately narrow. `activeApproved()` is the one place "approved and in
     * force" is expressed, and a superseded order is absent from this list for the
     * same reason the Form Request refuses it: the terms it sanctioned have been
     * replaced.
     *
     * @return Collection<int, PreDpcProposal>
     */
    private function raisablePreDpcs(Request $request): Collection
    {
        return PreDpcProposal::query()
            ->activeApproved()
            ->with(['promotionToPost', 'promotionSources.promotionFromPost', 'payScale'])
            ->where('department_id', $this->departmentId($request))
            ->orderByDesc('dpc_year')
            ->orderBy('id')
            ->get();
    }

    public function store(SaveDpcProposalRequest $request): RedirectResponse
    {
        $this->authorize('create', DpcProposal::class);

        /*
         | The Pre-DPC is re-fetched under the same three conditions the Form
         | Request checked — approved, active, and this department's.
         |
         | Not a wasted query: the request validated an id, and this is the object
         | the service inherits from. Fetching it here with the same scope means
         | there is no window in which a validated id becomes an unscoped lookup.
         */
        $preDpc = PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $request->user()->department_id)
            ->findOrFail($request->integer('pre_dpc_proposal_id'));

        $proposal = $this->service->createFromPreDpc($preDpc, $request->user(), $request->validated());

        return redirect()->route('dpc-proposals.show', $proposal)
            ->with('status', "Draft raised as {$proposal->citation()} from {$preDpc->pre_dpc_number}.");
    }

    public function edit(Request $request, DpcProposal $dpcProposal): View|RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        return view('dpc-proposals.form', $this->formData($request, $dpcProposal));
    }

    public function update(SaveDpcProposalRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $this->service->updateDraft($dpcProposal, $request->validated());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Draft updated.');
    }

    public function committee(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('committeeHistory.user', 'committeeHistory.replacer', 'dpcType');

        /*
         | The committee is composed here and nowhere else (change register of
         | 2026-08-26, items 6 to 9). There is no master to draw from any more:
         | a member is either an account the officer found by e-mail or mobile, or
         | a person recorded on the spot — and the six composition attributes are
         | properties of *this* membership rather than of the person.
         |
         | The CGPSC Chairman's seat is not offered as a row to edit. It is placed
         | by `DpcCommitteeService` on save, and shown here read-only so the officer
         | can see the committee they are actually composing.
         */
        return view('dpc-proposals.committee', [
            'proposal' => $dpcProposal,
            'rules' => $this->committeeRules->forProposal($dpcProposal),

            /*
             | Two lists rather than one (business clarification, 2026-09-05): the
             | three social categories are one choice, the rest combine freely, and
             | the form renders them as separate groups so the rule is visible
             | before it is enforced. Split here rather than in the view, so the
             | enum stays the one place that says which is which.
             */
            'socialCategories' => MemberCategory::socialCategories(),
            'additionalAttributes' => MemberCategory::additionalAttributes(),

            /*
             | A designation is chosen from the master, never typed
             | (DPC-MODULE-FIX-001, second pass).
             |
             | The Designation master rather than Department Post: a DPC committee
             | is drawn from across the government — the CGPSC Chairman and Members,
             | the Secretary of the department, the Deputy Secretary of GAD — and
             | `department_posts` holds one department's promotable cadre, which
             | none of those seats belong to. `designations` is the master that
             | already carries every one of them, including each department post's
             | own name, and it is the master `users.designation_id` points at.
             */
            'designations' => $this->selectableDesignations(),
            'chairman' => $this->chairmen->servingOn($dpcProposal->proposal_date ?? Carbon::today())
                ?? $this->chairmen->serving(),
        ]);
    }

    /**
     * Item 6: is this person already in the portal?
     *
     * Answers the search box and the live check behind the "Create New Member"
     * form from the same lookup, so the two cannot disagree about who already
     * exists. Returns only what the screen shows — a serialised `User` would put
     * the account's internal ids and timestamps on the wire for no reason.
     */
    public function searchCommitteeMembers(Request $request, DpcProposal $dpcProposal): JsonResponse
    {
        $this->authorize('update', $dpcProposal);

        $term = (string) $request->query('q', '');
        $matches = $this->directory->matching($term);

        return response()->json([
            'members' => $matches->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                // The id is what the card's dropdown selects; the name travels
                // with it only so the result line stays readable.
                'designation_id' => $user->designation_id,
                'designation' => $user->designation?->name,
                'department' => $user->activeAssignment?->department?->name,
            ])->values()->all(),
        ]);
    }

    public function updateCommittee(SaveDpcCommitteeRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        try {
            // `?? []` because the members list is nullable since 2026-09-06: an
            // officer may take every member off the form and save the draft.
            $this->committee->compose($dpcProposal, $request->validated('members') ?? [], $request->user());
        } catch (RuntimeException $e) {
            // The one that reaches here in practice is "no CGPSC Chairman is
            // recorded", which is an Administrator's task rather than a fault in
            // what the officer submitted — so it goes back to the form as a
            // message, not a 500.
            return back()->withInput()->withErrors(['members' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', __('dpc.committee.saved'));
    }

    public function workingOfficers(Request $request, DpcProposal $dpcProposal): View
    {
        /*
         | A proposal on the Administrator's Vacancy Management record: S16 is that
         | record's figures, read-only, for anyone who may view the proposal.
         */
        if ($dpcProposal->usesVacancyMaster()) {
            $this->authorize('view', $dpcProposal);

            $position = app(ProposalVacancyPosition::class)->for($dpcProposal);

            return view('dpc-proposals.admin-vacancy', [
                'proposal' => $dpcProposal,
                'position' => $position,
                'counts' => $position === null ? null : app(ProposalVacancyPosition::class)->counts($position),
                'vacancy' => $this->vacancies->summary($dpcProposal),
                'year' => (int) ($dpcProposal->dpc_year ?: $dpcProposal->proposal_date->year),
            ]);
        }

        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('vacancies.vacancyType', 'vacancies.reservationCategory', 'workingOfficers');

        return view('dpc-proposals.working-officers', [
            'proposal' => $dpcProposal,

            // A Post is chosen from the master, never typed (DPC-MODULE-FIX-001).
            'departmentPosts' => $this->selectablePosts($dpcProposal),
            // Working Officers, Total Vacancy and the Zone are derived, never
            // typed — the view renders them read-only and does no arithmetic
            // of its own (business directive, 2026-08-22).
            'vacancy' => $this->vacancies->summary($dpcProposal),

            /*
             | The breakdown as it stands on the **saved** chart (business
             | directive, 2026-09-02). Read from the stored rows rather than
             | re-derived here, so the screen shows what was recorded — a chart
             | edited but not yet saved should not appear to have already changed
             | the breakdown.
             */
            'derivedVacancies' => $dpcProposal->vacancies
                ->map(fn ($vacancy): array => [
                    'type' => $vacancy->vacancyType->name_en,
                    'category' => $vacancy->reservationCategory->code,
                    'count' => $vacancy->count,
                ])
                ->all(),

            'reservationCategories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
        ]);
    }

    public function updateWorkingOfficers(SaveWorkingOfficersRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $data = $request->validated();

        /*
         | **Officers first, then vacancies, and the order matters more than ever.**
         |
         | `saveVacancies()` records the vacancy calculation, and since the business
         | directive of 2026-09-02 it also *derives the breakdown rows* — both from
         | the officer chart. Called the other way round it would describe the chart
         | being replaced, and every figure would be one save behind for the rest of
         | the proposal's life.
         */
        /*
         | Manual entry is off by default since 2026-09-07 (business: *"remove manual
         | entry of Working Officers and the Seniority List — for now make it
         | available only through upload, hide it in case we want it back"*).
         |
         | Gated here as well as on the screen. Hiding a form and leaving its route
         | open removes the button rather than the feature, and this route accepts a
         | whole officer chart.
         |
         | The upload is unaffected: `ExcelImportService` calls the service
         | directly, which is what lets this be switched off without taking the
         | imports with it.
         */
        abort_unless(config('dpc.manual_entry.working_officers'), 403, __('dpc.working_officers.manual_entry_off'));

        $this->service->saveWorkingOfficers($dpcProposal, $data['officers'] ?? [], $request->user());
        $this->service->saveVacancies($dpcProposal->refresh(), $request->user());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Working officers and vacancies saved.');
    }

    public function seniorityList(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('seniorityEntries');

        return view('dpc-proposals.seniority-list', [
            'proposal' => $dpcProposal,

            // A Post is chosen from the master, never typed (DPC-MODULE-FIX-001).
            'departmentPosts' => $this->selectablePosts($dpcProposal),
            // The list must carry exactly the zone's officer count, so the
            // screen states the target before the officer starts typing rather
            // than refusing them afterwards.
            'vacancy' => $this->vacancies->summary($dpcProposal),
            'reservationCategories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
        ]);
    }

    public function updateSeniorityList(SaveSeniorityEntriesRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        // Off by default since 2026-09-07 — see `updateWorkingOfficers()` for why
        // the flag is checked on the route and not only on the screen.
        abort_unless(config('dpc.manual_entry.seniority_list'), 403, __('dpc.seniority_list.manual_entry_off'));

        $this->service->saveSeniorityEntries(
            $dpcProposal,
            $request->validated('entries') ?? [],
            $request->user(),
            $request->filled('seniority_list_as_on') ? Carbon::parse((string) $request->validated('seniority_list_as_on')) : null,
        );

        /*
         | Flashed, not shown inline: saving redirects, and the temporary passwords
         | are recoverable from nowhere else. An account created and never handed
         | over is an officer who cannot sign in, which is how item 17's login went
         | unusable in production until 2026-08-25.
         */
        return redirect()
            ->route('dpc-proposals.show', $dpcProposal)
            ->with('status', __('dpc.seniority_list.saved'))
            ->with('candidateAccounts', $this->service->lastCandidateProvisioning());
    }

    /**
     * Reissue a Promotion Candidate's temporary password (suggestion 4).
     *
     * Gated by `update` on the *proposal*, so the department that named the officer
     * is the department that can reissue for them — and once the proposal is
     * submitted, nobody can, which is the same rule as every other section.
     */
    public function reissueCandidatePassword(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:60'],
        ]);

        try {
            $password = $this->candidateAccounts->reissuePassword(
                $dpcProposal,
                $validated['employee_number'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['employee_number' => $e->getMessage()]);
        }

        // Flashed in the same shape the provisioning panel already renders, so one
        // partial shows a new account and a reissued one alike.
        return back()->with('candidateAccounts', [
            'created' => [$validated['employee_number'] => $password],
            'reused' => [],
            'skipped' => [],
            'conflicts' => [],
        ]);
    }

    public function evaluationChart(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load(
            'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',

            // The chart shows a count of the justified gaps per officer (change
            // register of 2026-08-27, items 14 to 19), so an officer can see at a
            // glance which rows have already been accounted for.
            'evaluationRows.recordGaps',
        );

        return view('dpc-proposals.evaluation-chart', [
            'proposal' => $dpcProposal,
            // Officers and their identity come from the Seniority List, so the
            // screen reports how many are expected against how many are present
            // rather than letting the officer add rows (business directive,
            // 2026-08-22).
            'chart' => $this->candidates->summary($dpcProposal),
            'reservationCategories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'crGrades' => config('dpc.evaluation.cr_grades'),
            'maxCrYears' => (int) config('dpc.evaluation.max_cr_years'),
            'noteCodes' => config('dpc.evaluation.note_codes'),
        ]);
    }

    /**
     * The Evaluation Chart template, pre-filled with this proposal's officers.
     *
     * A per-proposal download rather than the blank one on the Downloads screen:
     * a blank Evaluation Chart template is now of no use to anybody, because the
     * importer will only accept exactly the officers the Seniority List holds.
     */
    public function evaluationChartTemplate(Request $request, DpcProposal $dpcProposal): Response
    {
        $this->authorize('update', $dpcProposal);

        return response(
            // No explicit year: the builder anchors the per-year columns on the
            // chart's own data so a download-edit-upload round trip cannot drop
            // CR years that fall outside the proposal's year window.
            $this->templates->prefilledEvaluationCsv($dpcProposal),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'
                    .$this->templates->prefilledEvaluationFilename($dpcProposal).'"',
            ],
        );
    }

    public function updateEvaluationChart(SaveEvaluationChartRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $this->service->saveEvaluationRows($dpcProposal, $request->validated('rows') ?? [], $request->user());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Evaluation chart saved.');
    }

    /**
     * Every collection rendered on this screen is eager loaded, including the
     * reservation-category master each officer row displays and the three
     * per-year evaluation child tables (architect directive: no N+1 on
     * Proposal Detail). Covered by a query-count regression test.
     */
    public function show(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);
        $dpcProposal->load(
            'dpcType', 'promotionToPost', 'promotionFromPost.payScale', 'approver', 'preparer', 'signature',
            'preDpcProposal', 'payScale', 'recruitmentRule',
            'revisions.submitter', 'revisions.returner',
            'committeeMembers.user', 'vacancies.vacancyType', 'vacancies.reservationCategory', 'workingOfficers.reservationCategory',
            'seniorityEntries.reservationCategory',
            'evaluationRows.reservationCategory', 'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        );

        return view('dpc-proposals.show', [
            'proposal' => $dpcProposal,
            'checklist' => $this->completeness->checklist($dpcProposal),
            'verificationStatus' => $this->verification->status($dpcProposal),

            // Suggestion 9: the recorded rule stands, and the officer is told when
            // a newer one has taken its place rather than finding out at review.
            'ruleSuperseded' => $this->service->recruitmentRuleSuperseded($dpcProposal),
            'transitions' => WorkflowTransition::query()
                ->where('workflow', 'dpc_proposal')
                ->where('subject_type', $dpcProposal->getMorphClass())
                ->where('subject_id', $dpcProposal->id)
                ->with('actor')
                ->orderBy('occurred_at')
                ->orderBy('uuid')
                ->get(),
        ]);
    }

    /**
     * Administrative Head review walkthrough (PPT S21–S25) — M4 Phase 7.
     *
     * Read-only by construction: it renders the same section partials the
     * proposal detail screen uses, and the only actions it offers are the
     * single approve and the return, both on the final step.
     */
    public function review(Request $request, DpcProposal $dpcProposal, int $step = 1): View|RedirectResponse
    {
        $this->authorize('view', $dpcProposal);

        if ($step < 1 || $step > count(self::REVIEW_STEPS)) {
            return redirect()->route('dpc-proposals.review', [$dpcProposal, 1]);
        }

        $dpcProposal->load(
            'dpcType', 'promotionToPost', 'promotionFromPost.payScale', 'approver', 'preparer', 'signature',
            'preDpcProposal', 'payScale', 'recruitmentRule',
            'revisions.submitter', 'revisions.returner',
            'committeeMembers.user', 'vacancies.vacancyType', 'vacancies.reservationCategory', 'workingOfficers.reservationCategory',
            'seniorityEntries.reservationCategory',
            'evaluationRows.reservationCategory', 'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        );

        return view('dpc-proposals.review', [
            'proposal' => $dpcProposal,
            'step' => $step,
            'steps' => self::REVIEW_STEPS,
            'verificationStatus' => $this->verification->status($dpcProposal),
        ]);
    }

    public function submit(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('submit', $dpcProposal);

        return $this->runTransition($dpcProposal, fn () => $this->service->submit($dpcProposal, $request->user(), $request), 'Proposal submitted for approval.');
    }

    public function approve(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('approve', $dpcProposal);

        return $this->runTransition($dpcProposal, fn () => $this->service->approve($dpcProposal, $request->user(), $request), 'Proposal approved, signed, and forwarded to CGPSC.');
    }

    public function return(ReturnDpcProposalRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('return', $dpcProposal);

        return $this->runTransition($dpcProposal, fn () => $this->service->return($dpcProposal, $request->user(), (string) $request->validated('return_remarks'), $request), 'Proposal returned to the officer.');
    }

    public function cancel(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('cancel', $dpcProposal);

        return $this->runTransition($dpcProposal, fn () => $this->service->cancel($dpcProposal, $request->user(), $request), 'Proposal cancelled.');
    }

    private function runTransition(DpcProposal $dpcProposal, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return redirect()->route('dpc-proposals.show', $dpcProposal)->withErrors(['workflow' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', $success);
    }

    /**
     * The designations a committee member may be recorded as holding.
     *
     * Active rows only, and ordered by name: this is a picker, and a superseded
     * rank must not be offered to a committee being composed today.
     *
     * @return Collection<int, Designation>
     */
    private function selectableDesignations(): Collection
    {
        return Designation::query()
            ->where('status', MasterStatus::Active)
            ->orderBy('name')
            ->get();
    }

    /**
     * The Department Posts an officer row may name (DPC-MODULE-FIX-001).
     *
     * **A Post is a master entity and is never typed.** Both officer screens offer
     * this list instead of a free-text box, so a post can only be *chosen*.
     *
     * The department's own **active mappings still in force** — not merely the
     * proposal's own pair, because a Pre-DPC may sanction several feeder posts
     * (`pre_dpc_promotion_sources` is plural) and officers on one chart can
     * legitimately come from different feeders. `inForce()` excludes a superseded
     * mapping for the same reason the Pre-DPC form does: a mapping the Government
     * has replaced cannot describe a serving officer today.
     *
     * @return Collection<int, DepartmentPost>
     */
    private function selectablePosts(DpcProposal $proposal): Collection
    {
        return DepartmentPost::query()
            ->inForce()
            ->where('department_id', $proposal->department_id)
            ->orderBy('name')
            ->get();
    }

    private function departmentId(Request $request): ?int
    {
        return $request->user()->department_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, DpcProposal $proposal): array
    {
        return [
            'proposal' => $proposal,
            'dpcTypes' => DpcType::query()->where('status', MasterStatus::Active->value)->orderBy('name_en')->get(),

            // The posts list is gone (change item 11): the pair is inherited from
            // the Pre-DPC, so the form offers orders to raise from rather than
            // posts to choose between.
            'preDpcs' => $proposal->exists ? collect() : $this->raisablePreDpcs($request),
        ];
    }
}
