<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proceeding;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The sitting, as a committee member sees it (business instruction, 2026-09-07).
 *
 * > *When all the members click the DPC that is being held, the whole candidate list
 * > should appear. Clicking a candidate shows the full record, and a Call button
 * > appears — the call activates only on the date and time the DPC is scheduled for.
 * > Records should be shown candidate by candidate, and once a candidate's DPC is
 * > done, mark it concluded.*
 *
 * ### Authorised by a seat, not by a role
 *
 * Every other Commission screen is guarded on `role:chairman|member`, and a committee
 * member holds neither — item 7 forbids granting a Commission role for sitting on
 * one DPC's committee. So each action here asks
 * {@see CommitteeSittingService::isSeatedOn()} instead, and a person who is not on
 * *this* proposal's committee gets a 403 whatever else they hold.
 *
 * The check is repeated in every method rather than hoisted into a middleware,
 * because it is about *this* proposal and the middleware would have to re-resolve
 * the binding to know which.
 */
class CommitteeSittingController extends Controller
{
    public function __construct(private readonly CommitteeSittingService $sittings) {}

    /**
     * The DPCs this member sits on.
     */
    public function index(Request $request): View
    {
        return view('proceedings.sitting.index', [
            'proposals' => $this->sittings->sittingsFor($request->user()),
            'sittings' => $this->sittings,
        ]);
    }

    /**
     * The candidate list for one DPC.
     */
    public function show(Request $request, DpcProposal $dpcProposal): View
    {
        $this->assertSeated($request, $dpcProposal);

        return view('proceedings.sitting.show', [
            'proposal' => $dpcProposal,
            'candidates' => $this->sittings->candidates($dpcProposal),
            'schedule' => $this->sittings->schedule($dpcProposal),
            'callOpen' => $this->sittings->callWindowIsOpen($dpcProposal),
            'callReason' => $this->sittings->callWindowReason($dpcProposal),
        ]);
    }

    /**
     * One candidate's full record.
     */
    public function candidate(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $row): View
    {
        $this->assertSeated($request, $dpcProposal);
        $this->assertBelongs($dpcProposal, $row);

        $row->load([
            'reservationCategory', 'seatReservationCategory',
            'crGrades', 'loyaltyFlags', 'propertyFlags',
            'recordGaps.reason',
            'candidateCall.caller', 'candidateCall.concluder',
        ]);

        return view('proceedings.sitting.candidate', [
            'proposal' => $dpcProposal,
            'row' => $row,

            /*
             | The whole Evaluation Chart row, not a slice of it (business
             | instruction, 2026-09-07). Merged year by year in the service, because
             | the three per-year tables and the tribal-area column that rides on the
             | CR row are an arrangement a view should not have to know about.
             */
            'chartYears' => $this->sittings->chartYears($row),

            // What the engine made of them, where the DPC has been calculated.
            'result' => $this->sittings->resultFor($dpcProposal, $row),

            'schedule' => $this->sittings->schedule($dpcProposal),
            'callOpen' => $this->sittings->callWindowIsOpen($dpcProposal),
            'callReason' => $this->sittings->callWindowReason($dpcProposal),
        ]);
    }

    public function call(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $row): RedirectResponse
    {
        $this->assertSeated($request, $dpcProposal);
        $this->assertBelongs($dpcProposal, $row);

        try {
            $this->sittings->call($dpcProposal, $row, $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['call' => $e->getMessage()]);
        }

        return back()->with('status', __('proceeding.sitting.called', ['name' => $row->name]));
    }

    public function conclude(Request $request, DpcProposal $dpcProposal, DpcEvaluationRow $row): RedirectResponse
    {
        $this->assertSeated($request, $dpcProposal);
        $this->assertBelongs($dpcProposal, $row);

        $validated = $request->validate([
            // Not marks — the scheme is undecided. Whatever the committee wants
            // beside the candidate's name on the record.
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->sittings->conclude($dpcProposal, $row, $request->user(), $validated['remarks'] ?? null);
        } catch (WorkflowException $e) {
            return back()->withErrors(['call' => $e->getMessage()]);
        }

        return back()->with('status', __('proceeding.sitting.concluded', ['name' => $row->name]));
    }

    private function assertSeated(Request $request, DpcProposal $proposal): void
    {
        abort_unless(
            $this->sittings->isSeatedOn($proposal, $request->user()),
            403,
            __('proceeding.sitting.not_seated'),
        );
    }

    /**
     * A candidate reached through another DPC's URL is a 404, not a 403: the row
     * exists, but not on this proposal, and saying which would disclose it.
     */
    private function assertBelongs(DpcProposal $proposal, DpcEvaluationRow $row): void
    {
        abort_unless($row->dpc_proposal_id === $proposal->id, 404);
    }
}
