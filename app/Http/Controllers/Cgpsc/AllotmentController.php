<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Requests\AllotDpcRequest;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
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
            ->with('department', 'promotionFromPost', 'promotionToPost')
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

        /*
         | Each proposal's serving committee — read once and used twice below, for
         | who may preside and who may be allotted to. One query grouped in memory
         | rather than one per row, for the same reason the allotment lookups above
         | are: this is a list, and an N+1 here would grow with the Commission's
         | workload rather than with anything a test would notice.
         */
        $committee = DpcCommitteeMember::query()
            ->whereIn('dpc_proposal_id', $forwarded->pluck('id'))
            ->serving()
            ->with('user')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

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

            // Who may preside, per proposal (business clarification, 2026-09-05).
            'committeeByProposal' => $committee->groupBy('dpc_proposal_id'),

            /*
             | Who each DPC may be allotted to: **its own committee**, not every
             | Commission account (business instruction, 2026-09-06).
             |
             | > *When we already selected the members at DPC creation time, the
             | > Chairman should allot the DPC to one of those members — showing
             | > every member is wrong.*
             |
             | Filtered to seats that carry an account, because an allottee has to
             | sign in and act: they accept the DPC, schedule the sitting and
             | conduct it. A committee row describing somebody with no portal
             | account is a real member of the committee and simply cannot be the
             | one who executes it.
             |
             | Derived from the same collection the presiding list is, so the page
             | still costs one committee query however many DPCs are listed.
             */
            'allotteesByProposal' => $committee
                ->filter(fn (DpcCommitteeMember $seat): bool => $seat->user_id !== null)
                ->groupBy('dpc_proposal_id'),
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
            $this->completeBy($request),
            $this->dpcChairman($request, $dpcProposal),
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
            $this->completeBy($request),
            $this->dpcChairman($request, $dpcProposal),
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
            'proposal' => $dpcProposal->load('department', 'promotionFromPost', 'promotionToPost'),
            'events' => $this->allotments->historyFromTimeline($dpcProposal),
        ]);
    }

    private function allottee(AllotDpcRequest $request): User
    {
        return User::query()->findOrFail($request->validated('allotted_to'));
    }

    /**
     * The committee member the Chairman named to preside (business clarification,
     * 2026-09-05).
     *
     * Re-fetched under the same two conditions the request validated — this
     * proposal's committee, and still serving — rather than trusted from the
     * payload. The request proved an id; this is the object, and looking it up
     * with the same scope means there is no window in which a validated id
     * becomes an unscoped lookup.
     *
     * Nullable in the return type although the field is required, for the same
     * reason {@see completeBy()} is: the service must keep accepting null for the
     * non-HTTP callers and for allotments made before this field existed.
     */
    private function dpcChairman(AllotDpcRequest $request, DpcProposal $proposal): ?DpcCommitteeMember
    {
        $id = $request->validated('dpc_chairman_member_id');

        if (! is_numeric($id)) {
            return null;
        }

        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->whereKey((int) $id)
            ->first();
    }

    private function defaultDpcDate(AllotDpcRequest $request): ?Carbon
    {
        $value = $request->validated('default_dpc_date');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * The date this DPC must be concluded by (change register of 2026-08-26).
     *
     * Nullable here even though the request requires it, because the service
     * signature has to keep accepting null for the allotments made before the
     * deadline existed — a re-allotment of one of those must not be refused by a
     * type declaration.
     */
    private function completeBy(AllotDpcRequest $request): ?Carbon
    {
        $value = $request->validated('complete_by');

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
