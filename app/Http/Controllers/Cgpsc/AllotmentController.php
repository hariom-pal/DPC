<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Requests\AllotDpcRequest;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Chairman allotment screen and allotment report (PPT S27–S28) — M6 Phase 1.
 *
 * Proposals appear here only once the department has approved and forwarded
 * them (S19 → S26); until then they are not CGPSC's to allot.
 */
class AllotmentController extends Controller
{
    public function __construct(private readonly AllotmentService $allotments) {}

    /**
     * S28 — the allotment screen and, beneath it, the Allotment Report.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcAllotment::class);

        $forwarded = DpcProposal::query()
            ->where('status', DpcProposalStatus::Approved->value)
            ->whereNotNull('forwarded_at')
            ->with('department', 'postFromCadre', 'postToCadre')
            ->orderBy('forwarded_at')
            ->get();

        // One current-allotment lookup for the whole page rather than one per
        // proposal — the report is a list, and an N+1 here would grow with the
        // Commission's workload.
        $currentByProposal = DpcAllotment::query()
            ->whereIn('dpc_proposal_id', $forwarded->pluck('id'))
            ->current()
            ->with('allottee', 'allottedBy')
            ->get()
            ->keyBy('dpc_proposal_id');

        // The allotment each current one replaced, so S30's old-beside-new
        // display costs no extra query per row.
        $supersededByCurrent = DpcAllotment::query()
            ->whereIn('superseded_by_id', $currentByProposal->pluck('id'))
            ->with('allottee')
            ->get()
            ->keyBy('superseded_by_id');

        return view('cgpsc.allotments.index', [
            'proposals' => $forwarded,
            'currentByProposal' => $currentByProposal,
            'supersededByCurrent' => $supersededByCurrent,
            'allottees' => User::query()
                ->role(AllotDpcRequest::allottableRoles())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(AllotDpcRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('allot', DpcAllotment::class);

        return $this->run(fn () => $this->allotments->allot(
            $dpcProposal,
            $this->allottee($request),
            $request->user(),
            $request,
            $this->defaultDpcDate($request),
        ), 'DPC allotted.');
    }

    public function reallot(AllotDpcRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('reallot', DpcAllotment::class);

        return $this->run(fn () => $this->allotments->reallot(
            $dpcProposal,
            $this->allottee($request),
            $request->user(),
            $request,
            (string) $request->validated('reallotment_reason'),
            $this->defaultDpcDate($request),
        ), 'DPC re-allotted. The previous allotment is retained in the history.');
    }

    /**
     * Allotment history for one proposal, read entirely from timeline records
     * (architect directive) — never reconstructed from current state.
     */
    public function history(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('viewAny', DpcAllotment::class);

        return view('cgpsc.allotments.history', [
            'proposal' => $dpcProposal->load('department', 'postFromCadre', 'postToCadre'),
            'events' => $this->allotments->historyFromTimeline($dpcProposal),
        ]);
    }

    private function allottee(AllotDpcRequest $request): User
    {
        return User::query()->findOrFail($request->validated('allotted_to'));
    }

    private function defaultDpcDate(AllotDpcRequest $request): ?Carbon
    {
        $value = $request->validated('default_dpc_date');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return redirect()->route('cgpsc.allotments.index')->withErrors(['allotment' => $e->getMessage()]);
        }

        return redirect()->route('cgpsc.allotments.index')->with('status', $success);
    }
}
