<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Requests\RescheduleDpcRequest;
use App\Domain\Cgpsc\Requests\ScheduleDpcRequest;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Member acceptance and scheduling (PPT S29–S30) — M6 Phase 2.
 *
 * A Member sees only the DPCs allotted to them. The Chairman sees every
 * allotment, since he may allot to himself and remains accountable for the
 * Commission's schedule.
 */
class SchedulingController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly AllotmentService $allotments,
    ) {}

    /**
     * S30 — acceptance and scheduling, with the schedule report beneath.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DpcAllotment::class);

        $user = $request->user();

        $allotments = DpcAllotment::query()
            ->current()
            // A Member sees their own; the Chairman sees all.
            ->when(! $user->hasRole(Role::Chairman->value),
                fn ($query) => $query->where('allotted_to', $user->id))
            ->with([
                'allottee',
                'proposal.department',
                'proposal.postFromCadre',
                'proposal.postToCadre',
            ])
            ->orderBy('allotted_at')
            ->get();

        // One query for every current schedule rather than one per row.
        $schedules = DpcSchedule::query()
            ->whereIn('dpc_allotment_id', $allotments->pluck('id'))
            ->current()
            ->get()
            ->keyBy('dpc_allotment_id');

        // The schedule each current one replaced, so S30's old-beside-new
        // display needs no extra query per row.
        $previousSchedules = DpcSchedule::query()
            ->whereIn('superseded_by_id', $schedules->pluck('id'))
            ->get()
            ->keyBy('superseded_by_id');

        return view('cgpsc.schedules.index', [
            'allotments' => $allotments,
            'schedules' => $schedules,
            'previousSchedules' => $previousSchedules,
            'isAllottee' => fn (DpcAllotment $allotment): bool => $allotment->allotted_to === $user->id,
        ]);
    }

    public function accept(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $allotment = $this->authorizeAllottee($dpcProposal);

        return $this->run(
            fn () => $this->scheduling->accept($dpcProposal, $request->user(), $request),
            'DPC accepted. You can now set the date and time.',
        );
    }

    public function store(ScheduleDpcRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorizeAllottee($dpcProposal);

        return $this->run(fn () => $this->scheduling->schedule(
            $dpcProposal,
            $request->user(),
            $request,
            Carbon::parse((string) $request->validated('dpc_date')),
            (string) $request->validated('dpc_time'),
        ), 'DPC scheduled.');
    }

    public function reschedule(RescheduleDpcRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorizeAllottee($dpcProposal);

        return $this->run(fn () => $this->scheduling->reschedule(
            $dpcProposal,
            $request->user(),
            $request,
            Carbon::parse((string) $request->validated('dpc_date')),
            (string) $request->validated('dpc_time'),
            (string) $request->validated('reschedule_reason'),
        ), 'DPC re-scheduled. The previous date and time are retained in the history.');
    }

    /**
     * Scheduling history, read entirely from timeline records — never
     * reconstructed from current state.
     */
    public function history(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('viewAny', DpcAllotment::class);

        return view('cgpsc.schedules.history', [
            'proposal' => $dpcProposal->load('department', 'postFromCadre', 'postToCadre'),
            'events' => $this->scheduling->historyFromTimeline($dpcProposal),
        ]);
    }

    /**
     * Holding the Member role is not the same as being *this* DPC's member.
     * The route gates the role; this gates the individual, and the service
     * checks again so a non-HTTP caller is bound too.
     */
    private function authorizeAllottee(DpcProposal $proposal): DpcAllotment
    {
        $allotment = $this->allotments->current($proposal);

        abort_if($allotment === null, 404);
        $this->authorize('act', $allotment);

        return $allotment;
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return redirect()->route('cgpsc.schedules.index')->withErrors(['scheduling' => $e->getMessage()]);
        }

        return redirect()->route('cgpsc.schedules.index')->with('status', $success);
    }
}
