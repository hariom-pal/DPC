<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Eligibility\Services\ExplainCalculationService;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CALCULATE and calculation history (PPT S17/S18/S33) — M5 Phase 4.
 *
 * Authorization reuses M4's existing `view` ability on the parent proposal.
 * Running a calculation is deliberately **not** gated on `update`: a
 * calculation never mutates the proposal — it writes separate, immutable
 * evidence — and the Commission calculates *after* a proposal is submitted,
 * when the proposal itself is no longer editable. Gating on `update` would
 * make the engine unusable at exactly the point it is needed.
 *
 * Runs are addressed by **UUID only** (architect Decision 5); the
 * auto-increment id never appears in a URL. Individual officers within a run
 * are addressed by their serial number on the evaluation chart — business
 * data already printed on the PPT, not a database identifier.
 */
class DpcCalculationController extends Controller
{
    public function __construct(
        private readonly DpcCalculationService $calculations,
        private readonly ExplainCalculationService $explanations,
    ) {}

    /**
     * Calculation history for a proposal, newest first. Every run ever
     * executed is listed — none is ever replaced, so the history is the
     * record of what the Commission could have acted on.
     */
    public function index(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('viewCalculation', $dpcProposal);

        return view('dpc-proposals.calculations.index', [
            'proposal' => $dpcProposal,
            // Queried directly rather than through a relation on DpcProposal:
            // M4 is architecture-frozen, and M5 has no business adding methods
            // to its models to make its own screens convenient.
            'runs' => DpcCalculationRun::query()
                ->where('dpc_proposal_id', $dpcProposal->id)
                ->with('executedBy')
                ->latest('executed_at')
                ->paginate(20),
        ]);
    }

    public function store(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        // The DPC Chairman alone, once every member has agreed (2026-11-18).
        $this->authorize('viewCalculation', $dpcProposal);
        $gate = app(CalculationGate::class);

        try {
            // Re-checked under a lock, so two presses at once cannot both calculate.
            // With a proceeding open, the result is the proceeding's, so it can be approved.
            $run = $gate->calculateUnderLock($dpcProposal, $request->user(), function () use ($gate, $dpcProposal, $request) {
                $proceeding = $gate->proceeding($dpcProposal);

                return $proceeding !== null
                    ? app(ProceedingCalculationService::class)->calculate($proceeding, $request->user())
                    : $this->calculations->calculate($dpcProposal, $request->user());
            });

            if (is_string($run)) {
                throw new WorkflowException($run);
            }
        } catch (WorkflowException $e) {
            // Back to where the committee member pressed Calculate (2026-11-16): the
            // proposal for one who may view it, the sitting otherwise.
            return redirect()
                ->route($request->user()?->can('view', $dpcProposal) ? 'dpc-proposals.show' : 'sitting.show', $dpcProposal)
                ->withErrors(['calculate' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-proposals.calculations.show', [$dpcProposal, $run->uuid])
            ->with('status', 'Calculation complete.');
    }

    public function show(Request $request, DpcProposal $dpcProposal, DpcCalculationRun $calculationRun): View
    {
        $this->authorize('viewCalculation', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $calculationRun);

        $calculationRun->load('executedBy', 'results');

        return view('dpc-proposals.calculations.show', [
            'proposal' => $dpcProposal,
            'run' => $calculationRun,
            'runExplanation' => $this->explanations->explainRun($calculationRun),
            'mainList' => $calculationRun->mainList()->get(),
            'waitingList' => $calculationRun->waitingList()->get(),
            'allResults' => $calculationRun->results,
        ]);
    }

    /**
     * Explain Result for one officer (architect Decision 4 — an official
     * system capability). Addressed by the officer's serial number within the
     * run, never by a database id.
     */
    public function explain(Request $request, DpcProposal $dpcProposal, DpcCalculationRun $calculationRun, int $sn): View
    {
        $this->authorize('viewCalculation', $dpcProposal);
        $this->assertBelongsTo($dpcProposal, $calculationRun);

        $result = $calculationRun->results()->where('sn', $sn)->firstOrFail();

        return view('dpc-proposals.calculations.explain', [
            'proposal' => $dpcProposal,
            'run' => $calculationRun,
            'result' => $result,
            'explanation' => $this->explanations->explainResult($result),
        ]);
    }

    private function assertBelongsTo(DpcProposal $proposal, DpcCalculationRun $run): void
    {
        abort_unless($run->dpc_proposal_id === $proposal->id, 404);
    }
}
