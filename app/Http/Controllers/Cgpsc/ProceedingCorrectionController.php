<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingOverride;
use App\Domain\Proceeding\Requests\RecordCorrectionRequest;
use App\Domain\Proceeding\Requests\WithdrawCorrectionRequest;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Commission corrections during a sitting (PPT S33 EDIT / TRIBAL / SAVE) —
 * M7 Phase 2.
 *
 * `destroy` withdraws rather than deletes. The route verb is `DELETE` because
 * that is what the action means to the user; what it does to the record is
 * append another row.
 */
class ProceedingCorrectionController extends Controller
{
    public function __construct(private readonly ProceedingCorrectionService $corrections) {}

    /**
     * S33 — record a correction (`SAVE` on the EDIT or TRIBAL form).
     */
    public function store(RecordCorrectionRequest $request, DpcProceeding $proceeding): RedirectResponse
    {
        try {
            $this->corrections->record(
                $proceeding,
                $request->user(),
                CorrectableField::from((string) $request->input('field')),
                (int) $request->input('sn'),
                $request->input('year') === null ? null : (int) $request->input('year'),
                $request->input('new_value') === '' ? null : $request->input('new_value'),
                (string) $request->input('reason'),
            );
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['correction' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.show', $proceeding)
            ->with('status', 'The correction has been recorded. The department\'s entry is unchanged beside it.');
    }

    /**
     * S33 — withdraw a correction. Append-only: this records a further
     * correction restoring the previous value.
     */
    public function destroy(
        WithdrawCorrectionRequest $request,
        DpcProceeding $proceeding,
        DpcProceedingOverride $correction,
    ): RedirectResponse {
        try {
            $this->corrections->withdraw(
                $proceeding,
                $request->user(),
                $correction,
                (string) $request->input('reason'),
            );
        } catch (WorkflowException $e) {
            return back()->withErrors(['correction' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.show', $proceeding)
            ->with('status', 'The correction has been withdrawn. Both entries remain on the record.');
    }

    /**
     * Correction history, rendered from timeline records alone (architect
     * Directive 2).
     */
    public function index(DpcProceeding $proceeding): View
    {
        $this->authorize('view', $proceeding);

        $entries = $this->corrections->historyFromTimeline($proceeding);

        return view('cgpsc.proceedings.corrections', compact('proceeding', 'entries'));
    }
}
