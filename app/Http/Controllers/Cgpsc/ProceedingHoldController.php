<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Requests\RecordHoldRequest;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Held promotions (`CIR`) — PPT S45 — M7 Phase 4.
 *
 * Automatic holds are re-derived from the chart by `detect`; the Commission
 * may also record one of its own. Only the Commission's own may be released
 * here — an automatic hold clears itself once the entry that caused it is
 * corrected.
 */
class ProceedingHoldController extends Controller
{
    public function __construct(private readonly HoldService $holds) {}

    /**
     * Re-derive the automatic holds from the effective chart.
     */
    public function detect(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        $this->authorize('act', $proceeding);

        try {
            $this->holds->detect($proceeding, $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', 'Held promotions have been re-checked against the chart.');
    }

    public function store(RecordHoldRequest $request, DpcProceeding $proceeding): RedirectResponse
    {
        try {
            $this->holds->record(
                $proceeding,
                $request->user(),
                (int) $request->input('sn'),
                (string) $request->input('detail'),
            );
        } catch (WorkflowException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', 'The promotion has been held.');
    }

    public function destroy(Request $request, DpcProceeding $proceeding, DpcProceedingHold $hold): RedirectResponse
    {
        $this->authorize('act', $proceeding);

        try {
            $this->holds->release($proceeding, $request->user(), $hold);
        } catch (WorkflowException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', 'The hold has been released.');
    }
}
