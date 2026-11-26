<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Requests\ApproveResultRequest;
use App\Domain\Proceeding\Requests\CancelDpcRequest;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Proceeding\Services\ResultDocumentService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CALCULATE, the result, APPROVE BY CGPSC and CANCEL DPC (PPT S33–S34) —
 * M7 Phase 3.
 */
class ProceedingResultController extends Controller
{
    public function __construct(
        private readonly ProceedingCalculationService $calculations,
        private readonly ResultApprovalService $approvals,
        private readonly HoldService $holds,
    ) {}

    /**
     * S33 — CALCULATE. Every press creates a new run; the previous one stands.
     */
    public function calculate(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        $this->authorize('act', $proceeding);

        // The DPC Chairman alone, once every member has agreed, and again only after
        // the data changed (2026-11-18) — re-checked under a lock, so two presses at
        // once cannot both calculate.
        try {
            $proposal = $proceeding->proposal;
            $result = $proposal === null
                ? $this->calculations->calculate($proceeding, $request->user())
                : app(CalculationGate::class)->calculateUnderLock($proposal, $request->user(), fn () => $this->calculations->calculate($proceeding, $request->user()));

            if (is_string($result)) {
                return back()->withErrors(['result' => $result]);
            }
        } catch (WorkflowException $e) {
            return back()->withErrors(['result' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.result', $proceeding)
            ->with('status', 'The result has been calculated.');
    }

    /**
     * S34 — the Main List and Waiting List.
     */
    public function show(Request $request, DpcProceeding $proceeding): View
    {
        $this->authorize('view', $proceeding);

        $proceeding->load(['proposal.department', 'proposal.promotionFromPost', 'proposal.promotionToPost']);

        // An approved proceeding always shows the run it attested to, never
        // whatever was calculated afterwards.
        $run = $proceeding->final_calculation_run_id !== null
            ? $proceeding->finalCalculationRun
            : $this->calculations->latestRun($proceeding);

        return view('cgpsc.proceedings.result', [
            'proceeding' => $proceeding,
            'run' => $run,
            'lists' => $run === null ? null : $this->approvals->lists($run),
            'isStale' => $proceeding->isOpen() && $this->calculations->isStale($proceeding),
            'staleCorrections' => $proceeding->isOpen()
                ? $this->calculations->correctionsSinceCalculation($proceeding)
                : collect(),
            'history' => $this->calculations->history($proceeding),
            'holds' => $this->holds->activeBySn($proceeding),
            'canAct' => $request->user()?->can('act', $proceeding) ?? false,
            'defaultYear' => $proceeding->promotion_as_on_year
                ?? (int) $proceeding->proposal->proposal_date->format('Y'),
        ]);
    }

    /**
     * S34 — APPROVE BY CGPSC.
     */
    public function approve(ApproveResultRequest $request, DpcProceeding $proceeding): RedirectResponse
    {
        try {
            $this->approvals->approve(
                $proceeding,
                $request->user(),
                $request,
                (int) $request->input('promotion_as_on_year'),
            );
        } catch (WorkflowException $e) {
            return back()->withErrors(['result' => $e->getMessage()]);
        }

        // The result documents are made as the result is declared (2026-11-25), after
        // the approval has committed: a failure here never undoes the approval, and the
        // DPC Chairman can make them again from the sitting.
        $redirect = redirect()
            ->route('cgpsc.proceedings.result', $proceeding)
            ->with('status', 'The result has been approved by CGPSC. It is now a permanent record.');

        try {
            app(ResultDocumentService::class)->generate($proceeding->fresh(), $request->user());
        } catch (Throwable $e) {
            report($e);
            $redirect->withErrors(['result_documents' => __('proceeding.result_documents.generation_failed')]);
        }

        return $redirect;
    }

    /**
     * S33 — CANCEL DPC.
     */
    public function cancel(CancelDpcRequest $request, DpcProceeding $proceeding): RedirectResponse
    {
        try {
            $this->approvals->cancel($proceeding, $request->user(), $request, (string) $request->input('reason'));
        } catch (WorkflowException $e) {
            return back()->withErrors(['result' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.show', $proceeding)
            ->with('status', 'The DPC has been cancelled.');
    }
}
