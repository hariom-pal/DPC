<?php

declare(strict_types=1);

namespace App\Http\SignedActions;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ResultDocumentService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Contracts\SignedAction;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A committee member's eSign on every result document of a DPC (2026-11-25); the
 * last member's makes them final. Payload: proceeding (uuid).
 */
class SignResultDocuments implements SignedAction
{
    public function __construct(private readonly ResultDocumentService $documents) {}

    public function perform(Request $request, User $signer, array $payload): RedirectResponse
    {
        $proceeding = DpcProceeding::query()->where('uuid', (string) $payload['proceeding'])->firstOrFail();
        $back = route('sitting.show', $proceeding->proposal).'#result-documents';

        try {
            $this->documents->sign($proceeding, $signer);
        } catch (WorkflowException|SignatureVerificationFailed $e) {
            return redirect()->to($back)->withErrors(['result_documents' => $e->getMessage()]);
        }

        return redirect()->to($back)->with('status', $this->documents->isFinal($proceeding)
            ? __('proceeding.result_documents.signed_final')
            : __('proceeding.result_documents.signed'));
    }

    public function abandon(array $payload): RedirectResponse
    {
        $proceeding = DpcProceeding::query()->where('uuid', (string) $payload['proceeding'])->firstOrFail();

        return redirect()->to(route('sitting.show', $proceeding->proposal).'#result-documents');
    }
}
