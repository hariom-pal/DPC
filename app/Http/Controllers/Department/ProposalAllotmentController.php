<?php

declare(strict_types=1);

namespace App\Http\Controllers\Department;

use App\Domain\Allotment\Exceptions\AllotmentRefused;
use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Allotment\Requests\AllotProposalRequest;
use App\Domain\Allotment\Requests\OpenPreDpcRequest;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureAttemptLog;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Department Dashboard → Pre-DPC / DPC Allotment (business decisions A1–A5,
 * 2026-10-26; docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).
 *
 * The Department Admin opens a Pre-DPC (DPC year and promotion-to post) with its
 * AO and AH, and allots or reassigns the AO and AH of any Pre-DPC or DPC of the
 * department. The Department Admin reads every proposal here but never prepares
 * or approves one.
 */
class ProposalAllotmentController extends Controller
{
    public function __construct(
        private readonly DepartmentUserAuthority $authority,
        private readonly ProposalAllotmentService $allotments,
        private readonly PreDpcProposalService $preDpcs,
    ) {}

    /**
     * The department's DPC lists (2026-10-28):
     * - `pre-dpc`: Pre-DPCs not yet raised to a DPC;
     * - `running` (default): DPCs raised from a Pre-DPC and still in progress;
     * - `closed`: DPCs whose proceeding is closed or cancelled, or that were cancelled.
     * AO and AH are allotted and reassigned on the first two, until signed.
     */
    public function index(Request $request): View
    {
        $admin = $this->admin($request);
        $view = in_array($request->query('view'), ['pre-dpc', 'closed'], true) ? (string) $request->query('view') : 'running';

        if ($view === 'pre-dpc') {
            $rows = PreDpcProposal::query()
                ->where('department_id', $admin->department_id)
                ->where('status', '!=', 'cancelled')
                ->whereNotIn('id', DpcProposal::query()->whereNotNull('pre_dpc_proposal_id')->select('pre_dpc_proposal_id'))
                ->with(['promotionToPost', 'currentAllotment.ao', 'currentAllotment.ah'])
                ->orderByDesc('dpc_year')->orderByDesc('id')
                ->get();
        } else {
            // A closed proceeding (result approved) or a cancelled one closes the DPC.
            $proceedingClosed = fn ($p) => $p->selectRaw('1')->from('dpc_proceedings')
                ->whereColumn('dpc_proceedings.dpc_proposal_id', 'dpc_proposals.id')
                ->where(fn ($c) => $c->whereNotNull('dpc_proceedings.closed_at')->orWhereNotNull('dpc_proceedings.cancelled_at'));

            $rows = DpcProposal::query()
                ->where('department_id', $admin->department_id)
                ->when(
                    $view === 'closed',
                    fn ($q) => $q->where(fn ($w) => $w->where('status', 'cancelled')->orWhereExists($proceedingClosed)),
                    fn ($q) => $q->where('status', '!=', 'cancelled')->whereNotExists($proceedingClosed),
                )
                ->with(['promotionToPost', 'promotionFromPost', 'currentAllotment.ao', 'currentAllotment.ah'])
                ->latest('id')
                ->get();
        }

        return view('department.allotments.index', [
            'department' => $admin->department,
            'view' => $view,
            'kind' => $view === 'pre-dpc' ? 'pre_dpc' : 'dpc',
            'rows' => $rows,
            'issueIds' => app(SignatureAttemptLog::class)->withOpenIssues($rows),
        ]);
    }

    public function create(Request $request): View
    {
        $admin = $this->admin($request);

        return view('department.allotments.open', [
            'posts' => DepartmentPost::query()->inForce()->where('department_id', $admin->department_id)->orderBy('name')->get(),
        ]);
    }

    /**
     * Open the Pre-DPC (2026-10-28): its DPC Year and post only. The Department
     * Admin then fills in the rest; assigning the AO and AH is the last step, and
     * nobody sees the draft until then.
     */
    public function store(OpenPreDpcRequest $request): RedirectResponse
    {
        $admin = $this->admin($request);

        try {
            $proposal = $this->preDpcs->createDraft($admin->department, $admin, [
                'dpc_year' => $request->integer('dpc_year'),
                'promotion_to_post_id' => $request->integer('promotion_to_post_id'),
            ]);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['promotion_to_post_id' => $e->getMessage()]);
        }

        return redirect()->route('pre-dpc.edit', $proposal)
            ->with('status', __('allotments.opened_draft', ['number' => $proposal->pre_dpc_number]));
    }

    public function editPreDpc(Request $request, PreDpcProposal $preDpc): View
    {
        return $this->editForm($request, $preDpc, $preDpc->pre_dpc_number, route('department.allotments.pre-dpc.update', $preDpc));
    }

    public function updatePreDpc(AllotProposalRequest $request, PreDpcProposal $preDpc): RedirectResponse
    {
        return $this->allot($request, $preDpc, (string) $preDpc->pre_dpc_number);
    }

    public function editDpc(Request $request, DpcProposal $dpcProposal): View
    {
        return $this->editForm($request, $dpcProposal, $dpcProposal->citation(), route('department.allotments.dpc.update', $dpcProposal));
    }

    public function updateDpc(AllotProposalRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        return $this->allot($request, $dpcProposal, $dpcProposal->citation());
    }

    public function historyPreDpc(Request $request, PreDpcProposal $preDpc): View
    {
        return $this->history($request, $preDpc, (string) $preDpc->pre_dpc_number);
    }

    public function historyDpc(Request $request, DpcProposal $dpcProposal): View
    {
        return $this->history($request, $dpcProposal, $dpcProposal->citation());
    }

    private function editForm(Request $request, PreDpcProposal|DpcProposal $proposal, ?string $label, string $action): View
    {
        $this->admin($request, $proposal);

        return view('department.allotments.edit', [
            'label' => $label,
            'action' => $action,
            'current' => $this->allotments->current($proposal)?->loadMissing(['ao', 'ah']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->where('status', MasterStatus::Active->value)->orderBy('name')->get(['id', 'name']),
            'proposal' => $proposal,
        ]);
    }

    private function allot(AllotProposalRequest $request, PreDpcProposal|DpcProposal $proposal, string $label): RedirectResponse
    {
        $admin = $this->admin($request, $proposal);

        try {
            [$ao, $ah] = $this->chosenOfficers($request);

            // The last step (2026-10-28): a Pre-DPC is allotted only once complete.
            if ($proposal instanceof PreDpcProposal) {
                $this->preDpcs->assertComplete($proposal);
            }

            $this->allotments->allot($proposal, $ao, $ah, $admin, $request->input('reason'));
        } catch (AllotmentRefused|WorkflowException $e) {
            return back()->withInput()->withErrors(['ao_user_id' => $e->getMessage()]);
        }

        return redirect()->route('department.allotments.index', $proposal instanceof PreDpcProposal ? ['view' => 'pre-dpc'] : [])
            ->with('status', __('allotments.saved', ['proposal' => $label, 'ao' => $ao->name, 'ah' => $ah->name]));
    }

    private function history(Request $request, PreDpcProposal|DpcProposal $proposal, ?string $label): View
    {
        $this->admin($request, $proposal);

        return view('department.allotments.history', [
            'label' => $label,
            'rows' => ProposalAllotment::query()
                ->where($proposal instanceof PreDpcProposal ? 'pre_dpc_proposal_id' : 'dpc_proposal_id', $proposal->id)
                ->with(['ao', 'ah', 'allottedBy', 'endedBy'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * The signed-in Department Admin — of the proposal's department, when there is
     * one.
     */
    private function admin(Request $request, PreDpcProposal|DpcProposal|null $proposal = null): User
    {
        $admin = $request->user();

        abort_unless($admin instanceof User && $this->authority->isDepartmentAdmin($admin), 403);
        abort_if($proposal !== null && $proposal->department_id !== $admin->department_id, 403);

        return $admin;
    }

    /**
     * The department's active officers in a role, for the pickers.
     *
     * @return Collection<int, User>
     */
    /**
     * The chosen AO and AH: any active account of any department, found by its
     * e-mail on the screen (2026-10-28); ProposalAllotmentService decides who may
     * be allotted.
     *
     * @return array{0: User, 1: User}
     */
    private function chosenOfficers(Request $request): array
    {
        return [
            User::query()->findOrFail($request->integer('ao_user_id')),
            User::query()->findOrFail($request->integer('ah_user_id')),
        ];
    }
}
