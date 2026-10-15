<?php

declare(strict_types=1);

namespace App\Http\Controllers\PreDpc;

use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Services\PostAttributeResolver;
use App\Domain\Master\Services\PostAttributes;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Requests\ReturnPreDpcProposalRequest;
use App\Domain\PreDpc\Requests\SavePreDpcProposalRequest;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PreDpcProposalController extends Controller
{
    public function __construct(
        private readonly PreDpcProposalService $service,
        private readonly PostAttributeResolver $postAttributes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PreDpcProposal::class);

        return view('pre-dpc.index', [
            'proposals' => PreDpcProposal::query()
                ->with(['promotionToPost', 'promotionSources.promotionFromPost'])
                ->where('department_id', $this->departmentId($request))
                // Newest exercise first, then newest version within it: the list
                // is read to find "the current 2026 proposal for this pair".
                ->orderByDesc('dpc_year')
                ->orderByDesc('id')
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

        return redirect()->route('pre-dpc.show', $proposal)
            ->with('status', "Draft saved as {$proposal->pre_dpc_number}.");
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
        $preDpc->load(
            'promotionToPost.payScale',
            'promotionSources.promotionFromPost.payScale',
            'promotionSources.qualifications',
            'payScale', 'approver', 'signature', 'parentVersion',
        );

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

    /**
     * Discard a draft (business instruction, 2026-09-06).
     *
     * Redirects to the list rather than back, because "back" is the page for a
     * proposal that no longer appears anywhere.
     */
    public function destroy(Request $request, PreDpcProposal $preDpc): RedirectResponse
    {
        $this->authorize('delete', $preDpc);

        $number = $preDpc->pre_dpc_number;

        try {
            $this->service->delete($preDpc, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('pre-dpc.index')
            ->with('status', __('predpc.show.draft_deleted', ['number' => $number ?? '—']));
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
     * Pay Scale, Class and Promotion Duration for the posts asked about
     * (change item 5).
     *
     * **The form does not compute these; it asks for them.** The one derivation
     * lives in `PostAttributeResolver` and is what the service stores, so the boxes
     * the officer sees fill in cannot disagree with what is saved. A JSON endpoint
     * rather than a page of embedded post data because the Department Post master
     * grows and the mapping attached to a post can be superseded between opening the
     * form and submitting it.
     *
     * **Several posts in one call, and per post rather than per pair.** A promotion
     * may draw on several feeders and each has its own scale, class and qualifying
     * service; asking about them one at a time would cost a round trip per row and
     * let the rows on screen drift out of step with each other.
     *
     * Scoped to the signed-in officer's own department by the resolver, which
     * returns nothing for a post belonging to anyone else — so this cannot be used
     * to read another department's establishment.
     */
    public function derivedAttributes(Request $request): JsonResponse
    {
        $this->authorize('create', PreDpcProposal::class);

        $departmentId = $this->departmentId($request);
        abort_if($departmentId === null, 403);

        $ids = array_map('intval', (array) $request->input('post_ids', []));

        // A single `post_id` is accepted too, so one row changing does not have to
        // send the whole list.
        if ($request->filled('post_id')) {
            $ids[] = $request->integer('post_id');
        }

        return response()->json([
            'posts' => $this->postAttributes
                ->resolveMany($departmentId, array_values(array_filter($ids)))
                ->map(fn (PostAttributes $attributes): array => $attributes->toArray())
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, PreDpcProposal $proposal): array
    {
        $departmentId = $this->departmentId($request);

        return [
            'proposal' => $proposal,
            /*
             | Only the mapping **in force** may be named on a new Pre-DPC.
             |
             | `inForce()` rather than a status check: a superseded mapping is
             | inactive too, so the status filter alone would have worked by
             | accident. The scope says what is meant — a mapping the Government
             | has replaced cannot sanction a promotion today, even though it must
             | remain on record for the DPCs it did sanction.
             */
            'posts' => DepartmentPost::query()
                ->inForce()
                ->where('department_id', $departmentId)
                ->orderBy('name')
                ->get(),
            'qualifications' => Qualification::query()->selectable()->orderBy('name_en')->get(),
            'experienceUnits' => ExperienceUnit::cases(),

            // Pay Scale, Class and Promotion Duration are shown, never chosen
            // (item 5) — so the form is given the values for the posts already
            // selected and asks the endpoint above for the rest as rows change.
            'derived' => $this->postAttributes->resolveMany(
                $departmentId ?? 0,
                array_values(array_filter(array_merge(
                    [$proposal->promotion_to_post_id],
                    $proposal->promotionSources->pluck('promotion_from_post_id')->all(),
                ))),
            ),
        ];
    }
}
