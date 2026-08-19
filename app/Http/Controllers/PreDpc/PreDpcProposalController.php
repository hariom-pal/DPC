<?php

declare(strict_types=1);

namespace App\Http\Controllers\PreDpc;

use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\Qualification;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Requests\ReturnPreDpcProposalRequest;
use App\Domain\PreDpc\Requests\SavePreDpcProposalRequest;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PreDpcProposalController extends Controller
{
    public function __construct(private readonly PreDpcProposalService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PreDpcProposal::class);

        return view('pre-dpc.index', [
            'proposals' => PreDpcProposal::query()
                ->where('department_id', $this->departmentId($request))
                ->orderByDesc('version')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PreDpcProposal::class);

        return view('pre-dpc.form', $this->formData($request, new PreDpcProposal));
    }

    public function store(SavePreDpcProposalRequest $request): RedirectResponse
    {
        $this->authorize('create', PreDpcProposal::class);

        $department = $request->user()->department;
        abort_if($department === null, 403);

        $proposal = $this->service->createDraft($department, $request->user(), $request->validated());

        return redirect()->route('pre-dpc.show', $proposal)->with('status', 'Draft saved.');
    }

    public function edit(Request $request, PreDpcProposal $preDpc): View|RedirectResponse
    {
        $this->authorize('view', $preDpc);

        // Order matters, and it is not arbitrary. A proposal that is merely past
        // editing gets a redirect and an explanation; only an actor who may not
        // author at all gets a 403. Authorising `update` first would turn the
        // first case into the second and lose the message.
        if (! $preDpc->status->isEditable()) {
            return redirect()->route('pre-dpc.show', $preDpc)->with('status', 'Only draft/returned proposals can be edited.');
        }

        $this->authorize('update', $preDpc);

        $preDpc->load('posts.feeders');

        return view('pre-dpc.form', $this->formData($request, $preDpc));
    }

    public function update(SavePreDpcProposalRequest $request, PreDpcProposal $preDpc): RedirectResponse
    {
        $this->authorize('update', $preDpc);

        $this->service->updateDraft($preDpc, $request->validated());

        return redirect()->route('pre-dpc.show', $preDpc)->with('status', 'Draft updated.');
    }

    public function show(Request $request, PreDpcProposal $preDpc): View
    {
        $this->authorize('view', $preDpc);
        $preDpc->load('posts.feeders.feederCadre', 'posts.feeders.qualification', 'posts.payScale', 'approver', 'signature');

        return view('pre-dpc.show', [
            'proposal' => $preDpc,
            'transitions' => WorkflowTransition::query()
                ->where('workflow', 'pre_dpc')
                ->where('subject_type', $preDpc->getMorphClass())
                ->where('subject_id', $preDpc->id)
                ->with('actor')
                ->orderBy('occurred_at')
                ->orderBy('uuid')
                ->get(),
        ]);
    }

    public function submit(Request $request, PreDpcProposal $preDpc): RedirectResponse
    {
        return $this->runTransition($request, $preDpc, fn () => $this->service->submit($preDpc, $request->user(), $request), 'Proposal submitted for approval.');
    }

    public function approve(Request $request, PreDpcProposal $preDpc): RedirectResponse
    {
        return $this->runTransition($request, $preDpc, fn () => $this->service->approve($preDpc, $request->user(), $request), 'Proposal approved and signed.');
    }

    public function return(ReturnPreDpcProposalRequest $request, PreDpcProposal $preDpc): RedirectResponse
    {
        return $this->runTransition($request, $preDpc, fn () => $this->service->return($preDpc, $request->user(), (string) $request->validated('return_remarks'), $request), 'Proposal returned to the officer.');
    }

    public function cancel(Request $request, PreDpcProposal $preDpc): RedirectResponse
    {
        return $this->runTransition($request, $preDpc, fn () => $this->service->cancel($preDpc, $request->user(), $request), 'Proposal cancelled.');
    }

    public function amend(Request $request, PreDpcProposal $preDpc): RedirectResponse
    {
        $this->authorize('amend', $preDpc);

        $draft = $this->service->amend($preDpc, $request->user());

        return redirect()->route('pre-dpc.edit', $draft)->with('status', "New draft version v{$draft->version} created.");
    }

    /**
     * Submit, approve, return and cancel.
     *
     * Authorised here only to the **department boundary**. The role and the
     * legal state of each transition are enforced by `WorkflowEngine::assertAllowed()`,
     * which refuses with a message naming the transition and the state it was
     * attempted from — more use to a user than a bare 403, and the reason this
     * is deliberately not `authorize('submit', ...)`.
     */
    private function runTransition(Request $request, PreDpcProposal $preDpc, callable $action, string $success): RedirectResponse
    {
        $this->authorize('view', $preDpc);

        try {
            $action();
        } catch (WorkflowException $e) {
            return redirect()->route('pre-dpc.show', $preDpc)->withErrors(['workflow' => $e->getMessage()]);
        }

        return redirect()->route('pre-dpc.show', $preDpc)->with('status', $success);
    }

    private function departmentId(Request $request): ?int
    {
        return $request->user()->department_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, PreDpcProposal $proposal): array
    {
        $departmentId = $this->departmentId($request);

        return [
            'proposal' => $proposal,
            'payScales' => PayScale::query()->where('status', MasterStatus::Active->value)->orderBy('label')->get(),
            'cadres' => Cadre::query()->where('department_id', $departmentId)->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
            'qualifications' => Qualification::query()->selectable()->orderBy('name_en')->get(),
            'classes' => ServiceClass::cases(),
        ];
    }
}
