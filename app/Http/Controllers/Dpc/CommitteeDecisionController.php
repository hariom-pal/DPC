<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\CommitteeDecisionService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * MoM of 16 November 2026, points 2 and 3: a DPC Committee member's decision from
 * the sitting, and the AO's resubmission of a committee return.
 */
class CommitteeDecisionController extends Controller
{
    public function __construct(private readonly CommitteeDecisionService $decisions) {}

    public function store(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('committeeDecide', $dpcProposal);

        // Accept or Reject, with a remark — required to reject (2026-11-18).
        $data = $request->validate([
            'decision' => ['required', Rule::in([DpcCommitteeDecision::AGREED, DpcCommitteeDecision::RETURNED])],
            'remarks' => [Rule::requiredIf(fn (): bool => $request->input('decision') === DpcCommitteeDecision::RETURNED), 'nullable', 'string', 'max:2000'],
        ], ['remarks.required' => __('dpc.committee_decision.remarks_required')]);
        $sections = [];

        try {
            $this->decisions->decide($dpcProposal, $request->user(), $data['decision'], $data['remarks'] ?? null, $sections, $request);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('sitting.show', $dpcProposal)->with('status', __('dpc.committee_decision.recorded.'.$data['decision']));
    }

    /**
     * The DPC Chairman sends the accepted DPC back to the Secretary, with a remark
     * (2026-11-25): then as any rejected DPC — Secretary, AH, AO, and back.
     */
    public function returnToSecretary(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('committeeReturn', $dpcProposal);

        $data = $request->validate(
            ['return_remarks' => ['required', 'string', 'max:20000']],
            ['return_remarks.required' => __('dpc.committee_decision.remarks_required')],
        );

        try {
            $this->decisions->decide($dpcProposal, $request->user(), DpcCommitteeDecision::RETURNED, (string) $data['return_remarks'], [], $request);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['return_remarks' => $e->getMessage()]);
        }

        return redirect()->route('sitting.show', $dpcProposal)->with('status', __('dpc.committee_decision.returned_to_secretary'));
    }

    public function resubmit(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('committeeResubmit', $dpcProposal);

        try {
            $this->decisions->resubmit($dpcProposal, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['committee_resubmit' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', __('dpc.committee_decision.resubmitted'));
    }

    /** The Secretary's candidate-wise marking of a rejected DPC (2026-11-18). */
    public function rejectionForm(DpcProposal $dpcProposal): View
    {
        $this->authorize('secretaryMarkRejection', $dpcProposal);

        return view('dpc-proposals.committee-rejection', [
            'proposal' => $dpcProposal,
            'rows' => $dpcProposal->evaluationRows()->orderBy('sn')->get(),
            'sections' => CommitteeDecisionService::SECTIONS,
        ]);
    }

    public function markRejection(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('secretaryMarkRejection', $dpcProposal);

        $data = $request->validate([
            'candidates' => ['nullable', 'array'],
            'candidates.*' => ['array'],
            'candidates.*.*' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->decisions->markRejection($dpcProposal, $request->user(), array_map(fn ($sections): array => array_map('strval', array_filter((array) $sections, fn ($r) => $r !== null)), (array) ($data['candidates'] ?? [])), $request);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['candidates' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', __('dpc.committee_rejection.sent_to_ah'));
    }

    public function openForAo(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('ahOpenSections', $dpcProposal);

        try {
            $this->decisions->openForAo($dpcProposal, $request->user(), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['committee_rejection' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.show', $dpcProposal)->with('status', __('dpc.committee_rejection.opened_for_ao'));
    }
}
