<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proceedings document and promotion list (PPT S33–S34) — M7 Phase 4.
 *
 * Deliberately a separate controller from approval (architect Directive 3):
 * approval creates the legal decision, this renders it. Generating or
 * re-issuing a document can never alter a decision already taken.
 */
class ProceedingDocumentController extends Controller
{
    public function __construct(private readonly ProceedingDocumentService $documents) {}

    public function index(DpcProceeding $proceeding): View
    {
        $this->authorize('view', $proceeding);

        return view('cgpsc.proceedings.documents', [
            'proceeding' => $proceeding,
            'documents' => $this->documents->documents($proceeding),
            'versions' => $this->documents->allVersions($proceeding),
            'run' => $proceeding->finalCalculationRun,
            'canAct' => request()->user()?->can('act', $proceeding) ?? false,
        ]);
    }

    /**
     * Render → store → sign. Re-issuing creates a new version; the previous
     * one stands.
     */
    public function store(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        // A concluded proceeding fails the `act` policy, so generation is
        // authorised against the proposal's conductor instead: documents are
        // produced *after* approval, by the member who took the decision.
        $this->authorizeConductor($request, $proceeding);

        try {
            $this->documents->generate($proceeding, $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.documents.index', $proceeding)
            ->with('status', 'The documents have been generated and signed.');
    }

    /**
     * Release the documents to the department. Refused while the result is
     * provisional (Directive 5).
     */
    public function publish(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        $this->authorizeConductor($request, $proceeding);

        try {
            $this->documents->publish($proceeding, $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()
            ->route('cgpsc.proceedings.documents.index', $proceeding)
            ->with('status', 'The documents are now available to the department.');
    }

    /**
     * Stream a document through a policy check — never a public disk URL.
     */
    public function show(DpcProceeding $proceeding, DpcProceedingDocument $document): Response
    {
        $this->authorize('view', $proceeding);

        if ($document->dpc_proceeding_id !== $proceeding->id) {
            abort(404);
        }

        try {
            $contents = $this->documents->contents($document);
        } catch (WorkflowException $e) {
            abort(404, $e->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$document->type->filename().'"',
        ]);
    }

    /**
     * The conducting member remains responsible for the sitting's documents
     * after it has concluded, when `act` no longer applies.
     */
    private function authorizeConductor(Request $request, DpcProceeding $proceeding): void
    {
        abort_unless(
            $request->user() !== null
                && $request->user()->is_active
                && $proceeding->conducted_by === $request->user()->id,
            403,
        );
    }
}
