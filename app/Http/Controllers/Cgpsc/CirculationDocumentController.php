<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationDocument;
use App\Domain\Circulation\Services\CirculationDocumentService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Circulation documents — generate, download, verify, history, publish
 * (PPT S45, S48) — M9 Phase 3.
 *
 * **Publication is refused while BD-14 is unresolved (§23.1)**, and the
 * refusal is rendered as a structured *Pending Business Decision*, not as an
 * error. A document that simply never publishes with no reason given reads as
 * a system fault, and the response to a fault is to retry or work around it.
 */
class CirculationDocumentController extends Controller
{
    public function __construct(private readonly CirculationDocumentService $documents) {}

    /**
     * Current documents, full version history, and why publication is blocked
     * if it is.
     */
    public function index(DpcCirculation $dpcCirculation): View
    {
        $this->authorize('view', $dpcCirculation);

        return view('cgpsc.circulations.documents', [
            'circulation' => $dpcCirculation->load('items'),
            'documents' => $this->documents->documents($dpcCirculation),
            'history' => $this->documents->allVersions($dpcCirculation),
            // Shown up front rather than discovered on the publish button.
            'pending' => $this->documents->assessPublication($dpcCirculation)?->toArray(),
        ]);
    }

    public function store(DpcCirculation $dpcCirculation, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('manageDocuments', $dpcCirculation);

        try {
            $generated = $this->documents->generate($dpcCirculation, $request->user());
        } catch (WorkflowException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, $generated->count().' document(s) generated and signed.');
    }

    /**
     * Download. Available as soon as the document is generated — publication
     * governs release *to the department*, and a CGPSC reader may always see
     * what was produced in their own name.
     */
    public function download(
        DpcCirculation $dpcCirculation,
        DpcCirculationDocument $document,
        Request $request,
    ): Response|JsonResponse {
        $this->authorize('view', $dpcCirculation);

        if ($document->dpc_circulation_id !== $dpcCirculation->id) {
            abort(404);
        }

        try {
            $contents = $this->documents->contents($document);
        } catch (WorkflowException $e) {
            return $this->failResponse($request, $e->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$document->type->filename().'"',
        ]);
    }

    /**
     * Verification — does the stored file still match the hash taken when it
     * was generated?
     */
    public function verify(
        DpcCirculation $dpcCirculation,
        DpcCirculationDocument $document,
        Request $request,
    ): RedirectResponse|JsonResponse {
        $this->authorize('view', $dpcCirculation);

        if ($document->dpc_circulation_id !== $dpcCirculation->id) {
            abort(404);
        }

        try {
            $intact = $this->documents->verify($document);
        } catch (WorkflowException $e) {
            return $this->fail($request, $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'verified' => $intact,
                'content_hash' => $document->content_hash,
                'document_uuid' => $document->uuid,
            ]);
        }

        return back()->with(
            'status',
            $intact
                ? $document->type->label().' verified — the stored file matches its issued hash.'
                : $document->type->label().' FAILED verification — the stored file does not match its hash.'
        );
    }

    /**
     * Release the documents to the department.
     *
     * **§23.1** — refused while BD-14 is unresolved, with the reason displayed.
     */
    public function publish(DpcCirculation $dpcCirculation, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('manageDocuments', $dpcCirculation);

        try {
            $this->documents->publish($dpcCirculation, $request->user());
        } catch (PendingBusinessDecisionException $e) {
            // Not an error. The request was well-formed and the answer is
            // genuinely unruled (AD-2).
            if ($request->wantsJson()) {
                return response()->json($e->decision->toArray(), 409);
            }

            return back()->with('pendingBusinessDecision', $e->decision->toArray());
        } catch (WorkflowException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->ok($request, 'The circulation documents have been published to the department.');
    }

    // ------------------------------------------------------------- internals

    private function ok(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok', 'message' => $message]);
        }

        return back()->with('status', $message);
    }

    private function fail(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 422);
        }

        return back()->withErrors(['documents' => $message]);
    }

    private function failResponse(Request $request, string $message): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 422);
        }

        return response($message, 422);
    }
}
