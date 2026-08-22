<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\ReturnDpcProposalRequest;
use App\Domain\Dpc\Requests\SaveDpcCommitteeRequest;
use App\Domain\Dpc\Requests\SaveDpcProposalRequest;
use App\Domain\Dpc\Requests\SaveEvaluationChartRequest;
use App\Domain\Dpc\Requests\SaveSeniorityEntriesRequest;
use App\Domain\Dpc\Requests\SaveWorkingOfficersRequest;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EvaluationCandidateService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Import\Services\ImportTemplateBuilder;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcProposal::class);

        return view('dpc-proposals.index', [
            'proposals' => DpcProposal::query()
                ->where('department_id', $this->departmentId($request))
                ->with('dpcType', 'postToCadre', 'postFromCadre')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', DpcProposal::class);

        return view('dpc-proposals.form', $this->formData($request, new DpcProposal));
    }

    public function store(SaveDpcProposalRequest $request): RedirectResponse
    {
        $this->authorize('create', DpcProposal::class);

        $department = $request->user()->department;
        abort_if($department === null, 403);

        $proposal = $this->service->createDraft($department, $request->user(), $request->validated());

        return redirect()->route('dpc-proposals.show', $proposal)->with('status', 'Draft saved.');
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
        $dpcProposal->load('committeeMembers.user');

        return view('dpc-proposals.committee', [
            'proposal' => $dpcProposal,
            'candidateUsers' => User::query()
                ->role([Role::Chairman->value, Role::Member->value])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function updateCommittee(SaveDpcCommitteeRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $this->service->saveCommittee($dpcProposal, $request->validated('members'), $request->user());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Committee saved.');
    }

    public function workingOfficers(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('vacancies', 'workingOfficers');

        return view('dpc-proposals.working-officers', [
            'proposal' => $dpcProposal,
            // Working Officers, Total Vacancy and the Zone are derived, never
            // typed — the view renders them read-only and does no arithmetic
            // of its own (business directive, 2026-08-22).
            'vacancy' => $this->vacancies->summary($dpcProposal),
            'vacancyTypes' => VacancyType::query()->where('status', MasterStatus::Active->value)->orderBy('name_en')->get(),
            'reservationCategories' => ReservationCategory::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
        ]);
    }

    public function updateWorkingOfficers(SaveWorkingOfficersRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);

        $data = $request->validated();
        $this->service->saveVacancies($dpcProposal, (int) $data['sanctioned_post_count'], $data['vacancies'] ?? []);
        $this->service->saveWorkingOfficers($dpcProposal, $data['officers'] ?? [], $request->user());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Working officers and vacancies saved.');
    }

    public function seniorityList(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('seniorityEntries');

        return view('dpc-proposals.seniority-list', [
            'proposal' => $dpcProposal,
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

        $this->service->saveSeniorityEntries($dpcProposal, $request->validated('entries') ?? [], $request->user());

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', 'Seniority list saved.');
    }

    public function evaluationChart(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('update', $dpcProposal);
        $dpcProposal->load('evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags');

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
            'dpcType', 'postToCadre', 'postFromCadre', 'approver', 'preparer', 'signature',
            'committeeMembers.user', 'vacancies.vacancyType', 'vacancies.reservationCategory', 'workingOfficers.reservationCategory',
            'seniorityEntries.reservationCategory',
            'evaluationRows.reservationCategory', 'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        );

        return view('dpc-proposals.show', [
            'proposal' => $dpcProposal,
            'checklist' => $this->completeness->checklist($dpcProposal),
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
            'dpcType', 'postToCadre', 'postFromCadre', 'approver', 'preparer', 'signature',
            'committeeMembers.user', 'vacancies.vacancyType', 'vacancies.reservationCategory', 'workingOfficers.reservationCategory',
            'seniorityEntries.reservationCategory',
            'evaluationRows.reservationCategory', 'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        );

        return view('dpc-proposals.review', [
            'proposal' => $dpcProposal,
            'step' => $step,
            'steps' => self::REVIEW_STEPS,
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

    private function departmentId(Request $request): ?int
    {
        return $request->user()->department_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, DpcProposal $proposal): array
    {
        $departmentId = $this->departmentId($request);

        return [
            'proposal' => $proposal,
            'dpcTypes' => DpcType::query()->where('status', MasterStatus::Active->value)->orderBy('name_en')->get(),
            'cadres' => Cadre::query()->where('department_id', $departmentId)->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
        ];
    }
}
