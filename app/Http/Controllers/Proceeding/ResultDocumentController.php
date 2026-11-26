<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proceeding;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcResultDocument;
use App\Domain\Proceeding\Services\ResultDocumentService;
use App\Domain\Proceeding\Services\SealedCoverService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\EsignGate;
use App\Http\Controllers\Controller;
use App\Http\SignedActions\SignResultDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The result documents of a DPC (2026-11-25): read by its committee and the Commission,
 * by the department once final; eSigned by each committee member. No role guard, for
 * the sitting's reason — a committee member may hold none; every action asks
 * ResultDocumentService.
 */
class ResultDocumentController extends Controller
{
    public function __construct(private readonly ResultDocumentService $documents) {}

    public function show(Request $request, DpcProceeding $proceeding, DpcResultDocument $document): Response
    {
        abort_unless($document->dpc_proceeding_id === $proceeding->id, 404);
        abort_unless($this->documents->mayView($proceeding, $request->user()), 403);

        try {
            $contents = $this->documents->contents($document);
        } catch (WorkflowException $e) {
            abort(409, $e->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename($document->final_path !== null).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Each member's eSign on them all, through the eSign. */
    public function sign(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        abort_unless($this->documents->mayView($proceeding, $request->user()), 403);

        if (($refusal = $this->documents->signRefusal($proceeding, $request->user())) !== null) {
            return back()->withErrors(['result_documents' => $refusal]);
        }

        return app(EsignGate::class)->begin($request, $request->user(), SignResultDocuments::class, ['proceeding' => $proceeding->uuid]);
    }

    /**
     * Make them, where approving the result could not (the DPC Chairman or the member
     * who conducted the sitting).
     */
    public function generate(Request $request, DpcProceeding $proceeding): RedirectResponse
    {
        $user = $request->user();
        abort_unless($proceeding->conducted_by === $user->id || app(SealedCoverService::class)->isDpcChairman($proceeding->proposal, $user), 403);

        try {
            $this->documents->generate($proceeding, $user);
        } catch (WorkflowException $e) {
            return back()->withErrors(['result_documents' => $e->getMessage()]);
        }

        return back()->with('status', __('proceeding.result_documents.generated'));
    }
}
