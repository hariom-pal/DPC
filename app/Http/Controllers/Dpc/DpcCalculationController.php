<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Eligibility\Services\ExplainCalculationService;
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
        $this->authorize('view', $dpcProposal);

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
        $this->authorize('view', $dpcProposal);

        try {
            $run = $this->calculations->calculate($dpcProposal, $request->user());
        } catch (WorkflowException $e) {
            return redirect()
                ->route('dpc-proposals.show', $dpcProposal)
                ->withErrors(['calculate' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-proposals.calculations.show', [$dpcProposal, $run->uuid])
            ->with('status', 'Calculation complete.');
    }

    public function show(Request $request, DpcProposal $dpcProposal, DpcCalculationRun $calculationRun): View
    {
        $this->authorize('view', $dpcProposal);
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
        $this->authorize('view', $dpcProposal);
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
