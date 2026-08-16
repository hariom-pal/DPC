<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Requests\RaiseCirculationRequest;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Circulation DPC — the department's side (PPT S45, S47) — M9 Phase 1.
 *
 * Thin, as every controller in this system is (AD-9): it authorises, hands to
 * a service, and renders. The one piece of real logic here is turning a
 * `PendingBusinessDecisionException` into a **structured** response rather than
 * a validation error, because a case blocked on CGPSC is not a mistake the
 * department made and must not be presented as one.
 */
class CirculationController extends Controller
{
    public function __construct(
        private readonly CirculationService $circulations,
        private readonly CirculationWorkflowService $workflow,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcCirculation::class);

        return view('dpc.circulations.index', [
            'circulations' => DpcCirculation::query()
                ->where('department_id', $request->user()->department_id)
                ->with(['items', 'originProposal'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * S47 — the circulation action screen.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', DpcCirculation::class);

        $origins = $this->circulations->eligibleOrigins((int) $request->user()->department_id);

        $selected = $request->query('origin')
            ? $origins->firstWhere('uuid', (string) $request->query('origin'))
            : $origins->first();

        return view('dpc.circulations.create', [
            'origins' => $origins,
            'selected' => $selected,
            'holds' => $selected === null
                ? collect()
                : $this->circulations->outstandingHolds($selected),
        ]);
    }

    public function store(RaiseCirculationRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', DpcCirculation::class);

        $origin = DpcProposal::query()
            ->where('uuid', (string) $request->input('origin_dpc_proposal_uuid'))
            ->firstOrFail();

        /** @var array<int, array{hold_uuid:string, resolution_detail:string, resolution_reference?:string|null, resolution_received_on:string}> $items */
        $items = array_values((array) $request->input('items', []));

        try {
            $circulation = $this->circulations->raise(
                $origin,
                $request->user(),
                Carbon::parse((string) $request->input('proposal_date')),
                $items,
            );
        } catch (PendingBusinessDecisionException $e) {
            // Not a validation failure and not an error. The request was
            // well-formed; the answer is genuinely unknown, and the system is
            // declining to invent one (AD-2).
            if ($request->wantsJson()) {
                return response()->json($e->decision->toArray(), 409);
            }

            return back()
                ->withInput()
                ->with('pendingBusinessDecision', $e->decision->toArray());
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['circulation' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-circulations.show', $circulation)
            ->with('status', 'The circulation has been raised. The DPC and its result are unchanged.');
    }

    public function show(DpcCirculation $dpcCirculation): View
    {
        $this->authorize('view', $dpcCirculation);

        return view('dpc.circulations.show', [
            'circulation' => $dpcCirculation->load(['items.hold', 'originProposal', 'raisedBy', 'approvedBy']),
        ]);
    }

    public function submit(DpcCirculation $dpcCirculation, Request $request): RedirectResponse
    {
        $this->authorize('submit', $dpcCirculation);

        try {
            $this->workflow->submit($dpcCirculation, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['circulation' => $e->getMessage()]);
        }

        return back()->with('status', 'Submitted to the Administrative Head.');
    }

    public function approve(DpcCirculation $dpcCirculation, Request $request): RedirectResponse
    {
        $this->authorize('approve', $dpcCirculation);

        try {
            $circulation = $this->workflow->approve($dpcCirculation, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['circulation' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Approved and signed. Circulation '.$circulation->circulation_code.' is forwarded to the Commission.'
        );
    }

    public function return(DpcCirculation $dpcCirculation, Request $request): RedirectResponse
    {
        $this->authorize('return', $dpcCirculation);

        $validated = $request->validate(['remarks' => ['required', 'string', 'max:2000']]);

        try {
            $this->workflow->return($dpcCirculation, $request->user(), $validated['remarks'], $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['circulation' => $e->getMessage()]);
        }

        return back()->with('status', 'Returned to the Authorized Officer.');
    }

    public function cancel(DpcCirculation $dpcCirculation, Request $request): RedirectResponse
    {
        $this->authorize('cancel', $dpcCirculation);

        try {
            $this->workflow->cancel($dpcCirculation, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['circulation' => $e->getMessage()]);
        }

        return redirect()->route('dpc-circulations.index')->with('status', 'The circulation has been cancelled.');
    }
}
