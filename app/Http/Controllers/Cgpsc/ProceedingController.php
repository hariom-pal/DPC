<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Requests\OpenProceedingRequest;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The DPC sitting (PPT S31–S34) — M7 Phase 1.
 *
 * Phase 1 opens a proceeding and shows the workspace **read-only**. The S33
 * controls — SAVE, EDIT, TRIBAL, CALCULATE, CANCEL DPC — arrive with Phases 2
 * and 3, and are deliberately absent rather than present and inert: a button
 * that does nothing is worse than no button.
 */
class ProceedingController extends Controller
{
    public function __construct(
        private readonly ProceedingService $proceedings,
        private readonly ProceedingCorrectionService $corrections,
        private readonly AllotmentService $allotments,
    ) {}

    /**
     * S32 — the DPCs a member may take up, entered by DPC ID.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcProceeding::class);

        $user = $request->user();

        $allotments = DpcAllotment::query()
            ->current()
            // A Member sees their own; the Chairman sees all, because he
            // remains accountable for the Commission's work — but reading is
            // not conducting (AQ-6).
            ->when(! $user->hasRole(Role::Chairman->value),
                fn ($query) => $query->where('allotted_to', $user->id))
            ->with(['allottee', 'proposal.department', 'proposal.promotionFromPost', 'proposal.promotionToPost'])
            ->orderBy('allotted_at')
            ->get();

        // One keyed query each for schedules and proceedings — never one per
        // row. The list is short today, but an N+1 here would grow with the
        // Commission's workload rather than with anything a test would catch.
        $schedules = DpcSchedule::query()
            ->whereIn('dpc_allotment_id', $allotments->pluck('id'))
            ->current()
            ->get()
            ->keyBy('dpc_allotment_id');

        $proceedings = DpcProceeding::query()
            ->whereIn('dpc_proposal_id', $allotments->pluck('dpc_proposal_id'))
            ->orderBy('opened_at')
            ->get()
            ->keyBy('dpc_proposal_id');

        return view('cgpsc.proceedings.index', [
            'allotments' => $allotments,
            'schedules' => $schedules,
            'proceedings' => $proceedings,
            'isAllottee' => fn (DpcAllotment $allotment): bool => $allotment->allotted_to === $user->id,
        ]);
    }

    /**
     * S31 — open the sitting.
     */
    public function open(OpenProceedingRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        // Defence in depth, and a proper 403 rather than a validation message:
        // the allottee check is enforced here on the existing allotment policy,
        // and again inside the service so a non-HTTP caller is bound too.
        $allotment = $this->allotments->current($dpcProposal);

        if ($allotment !== null) {
            $this->authorize('act', $allotment);
        }

        try {
            $proceeding = $this->proceedings->open($dpcProposal, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['proceeding' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.show', $proceeding)
            ->with('status', 'The DPC proceeding has been opened.');
    }

    /**
     * S33 — the proceeding workspace, editable while the sitting is open.
     */
    public function show(Request $request, DpcProceeding $proceeding): View
    {
        $this->authorize('view', $proceeding);

        $proceeding->load([
            'proposal.department',
            'proposal.promotionFromPost',
            'proposal.promotionToPost',
            'proposal.evaluationRows.reservationCategory',
            'proposal.evaluationRows.crGrades',
            'proposal.evaluationRows.loyaltyFlags',
            'proposal.evaluationRows.propertyFlags',
            'schedule',
            'conductedBy',
        ]);

        // The department's chart with corrections applied. Assembled once,
        // in two queries, and reused by every cell.
        $chart = $this->corrections->effectiveChart($proceeding);

        // The union of every year present on the chart, so the table renders
        // the department's own columns rather than a fixed range.
        $years = $proceeding->proposal->evaluationRows
            ->flatMap(fn ($row) => $row->crGrades->pluck('year'))
            ->merge($proceeding->proposal->evaluationRows->flatMap(fn ($row) => $row->loyaltyFlags->pluck('year')))
            ->merge($proceeding->proposal->evaluationRows->flatMap(fn ($row) => $row->propertyFlags->pluck('year')))
            ->unique()->sort()->values();

        return view('cgpsc.proceedings.show', [
            'proceeding' => $proceeding,
            'chart' => $chart,
            'years' => $years,
            'corrections' => $this->corrections->inForce($proceeding)->values(),
            'correctableFields' => CorrectableField::enabled(),
            // The Chairman may read a sitting he is not conducting; only the
            // conducting member may change anything in it (AQ-6).
            'canEdit' => $request->user()?->can('act', $proceeding) ?? false,
        ]);
    }

    /**
     * Proceeding history, rendered from timeline records alone.
     */
    public function history(DpcProceeding $proceeding): View
    {
        $this->authorize('view', $proceeding);

        $entries = $this->proceedings->historyFromTimeline($proceeding->proposal);

        return view('cgpsc.proceedings.history', compact('proceeding', 'entries'));
    }
}
