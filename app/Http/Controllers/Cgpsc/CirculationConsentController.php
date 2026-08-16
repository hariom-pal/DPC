<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Circulation\Services\CirculationCommissionService;
use App\Domain\Circulation\Services\CirculationResultService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Circulation DPC — the Commission's side (PPT S46, S48) — M9 Phase 2.
 *
 * S46 is the Chairman/Member login (M1, unchanged). S48 is this: the origin's
 * approved result shown **before and after**, a decision per held officer, and
 * the signature that releases the promotions consented to.
 *
 * Thin, as every controller here is (AD-9). It performs no DPC execution
 * (AD-23) — the result it renders is read as stored data, never recalculated.
 */
class CirculationConsentController extends Controller
{
    public function __construct(
        private readonly CirculationCommissionService $commission,
        private readonly CirculationResultService $results,
    ) {}

    /**
     * Circulations awaiting the Commission.
     */
    public function index(): View
    {
        $this->authorize('viewAny', DpcCirculation::class);

        return view('cgpsc.circulations.index', [
            'circulations' => DpcCirculation::query()
                ->whereIn('status', [
                    CirculationStatus::Approved->value,
                    CirculationStatus::Consented->value,
                ])
                ->with(['items', 'department', 'originProposal'])
                ->orderBy('approved_at')
                ->get(),
            'signed' => DpcCirculation::query()
                ->where('status', CirculationStatus::Signed->value)
                ->with(['items', 'department'])
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * S48 — the before/after result, and the decision panel.
     */
    public function show(DpcCirculation $dpcCirculation): View
    {
        $this->authorize('view', $dpcCirculation);

        return view('cgpsc.circulations.show', [
            'circulation' => $dpcCirculation->load(['items.hold', 'originProposal', 'originProceeding', 'department']),
            'rows' => $this->results->beforeAndAfter($dpcCirculation),
            'run' => $this->results->approvedRun($dpcCirculation),
        ]);
    }

    /**
     * Record the Commission's decision on one officer.
     */
    public function decide(
        DpcCirculation $dpcCirculation,
        DpcCirculationItem $item,
        Request $request,
    ): RedirectResponse|JsonResponse {
        $this->authorize('consent', $dpcCirculation);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:consented,returned'],
        ]);

        try {
            $decided = $this->commission->decide(
                $dpcCirculation,
                $item,
                $request->user(),
                CirculationDecision::from($validated['decision']),
            );
        } catch (WorkflowException $e) {
            return $this->fail($request, $e);
        }

        if ($request->wantsJson()) {
            return response()->json($decided->toTimelineProperties());
        }

        return back()->with('status', $decided->officer_name.' — '.$decided->decision->label().'.');
    }

    /**
     * `APPROVE BY CGPSC` on S48.
     */
    public function consent(DpcCirculation $dpcCirculation, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('consent', $dpcCirculation);

        try {
            $this->commission->consent($dpcCirculation, $request->user(), $request);
        } catch (WorkflowException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, 'Consented. The note is ready for signature.');
    }

    /**
     * Sign the note — the operative act. Holds are released here (AQ-17).
     */
    public function sign(DpcCirculation $dpcCirculation, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('sign', $dpcCirculation);

        try {
            $signed = $this->commission->sign($dpcCirculation, $request->user(), $request);
        } catch (WorkflowException $e) {
            return $this->fail($request, $e);
        }

        $released = $signed->items()->whereNotNull('hold_released_at')->count();

        return $this->ok(
            $request,
            $released === 0
                ? 'Signed. No promotion was released — every officer was returned for further evidence.'
                : "Signed. {$released} held promotion(s) released."
        );
    }

    public function returnToDepartment(DpcCirculation $dpcCirculation, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('returnToDepartment', $dpcCirculation);

        $validated = $request->validate(['remarks' => ['required', 'string', 'max:2000']]);

        try {
            $this->commission->returnToDepartment(
                $dpcCirculation,
                $request->user(),
                $validated['remarks'],
                $request,
            );
        } catch (WorkflowException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, 'Returned to the department for further evidence.');
    }

    // ------------------------------------------------------------- internals

    private function ok(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok', 'message' => $message]);
        }

        return back()->with('status', $message);
    }

    private function fail(Request $request, WorkflowException $e): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return back()->withErrors(['circulation' => $e->getMessage()]);
    }
}
