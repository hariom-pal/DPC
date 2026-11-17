<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\CommitteeDecisionService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
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

        $data = $request->validate([
            'decision' => ['required', Rule::in([DpcCommitteeDecision::AGREED, DpcCommitteeDecision::RETURNED])],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', Rule::in(CommitteeDecisionService::SECTIONS)],
            'section_remarks' => ['nullable', 'array'],
            'section_remarks.*' => ['nullable', 'string', 'max:2000'],
        ]);

        // Each section returned carries its remark (MoM point 3).
        $sections = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            $remark = trim((string) ($data['section_remarks'][$section] ?? ''));
            if ($remark === '') {
                return back()->withInput()->withErrors(['section_remarks.'.$section => __('dpc.committee_decision.remark_required', ['section' => __('dpc.committee_decision.sections.'.$section)])]);
            }
            $sections[$section] = $remark;
        }

        try {
            $this->decisions->decide($dpcProposal, $request->user(), $data['decision'], $data['remarks'] ?? null, $sections, $request);
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('sitting.show', $dpcProposal)->with('status', __('dpc.committee_decision.recorded.'.$data['decision']));
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
}
