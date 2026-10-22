<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalRepresentation;
use App\Domain\Dpc\Models\DpcProposalVerification;
use App\Domain\Dpc\Requests\ConfirmProposalVerificationRequest;
use App\Domain\Dpc\Requests\UploadRepresentationRequest;
use App\Domain\Dpc\Services\ProposalRepresentationService;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rule 1.1 — the department's pre-submission confirmations.
 *
 * A step of the proposal like the S15–S18 sections, reached from the readiness
 * checklist on the proposal screen. Confirming and withdrawing are editing the
 * proposal, so both are gated on `update` — the Authorized Officer, own department,
 * Draft or Returned. Reading the screen and the minutes is `view`, so the
 * Administrative Head can see what was confirmed before signing.
 */
class DpcProposalVerificationController extends Controller
{
    public function __construct(
        private readonly ProposalVerificationService $verification,
        private readonly VacancyCalculationService $vacancies,
        private readonly VacancyEngine $engine,
    ) {}

    public function index(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);

        $dpcProposal->loadMissing('promotionToPost', 'promotionFromPost');

        return view('dpc-proposals.verification', [
            'proposal' => $dpcProposal,
            'status' => $this->verification->status($dpcProposal),
            'history' => $this->verification->history($dpcProposal),
            'summary' => $this->vacancies->summary($dpcProposal),
            'rule' => $this->engine->recruitmentRule($dpcProposal),
            'previousCandidates' => $this->verification->previousDpcCandidates($dpcProposal),
            // Rule 1.1: offline DPCs the Commission's staff recorded for this post.
            'previousRecords' => $this->verification->previousDpcRecords($dpcProposal),
            'bases' => PreviousDpcBasis::cases(),
            'canEdit' => $request->user()->can('update', $dpcProposal),
            // Rule 4.4: representations and complaints received about the DPC.
            'representations' => DpcProposalRepresentation::query()->where('dpc_proposal_id', $dpcProposal->id)
                ->with('uploader')->orderBy('id')->get(),
        ]);
    }

    public function storeRepresentation(UploadRepresentationRequest $request, DpcProposal $dpcProposal, ProposalRepresentationService $representations): RedirectResponse
    {
        try {
            $representations->upload($dpcProposal, $request->safe()->except('document'), $request->file('document'), $request->user());
        } catch (WorkflowException $e) {
            return redirect()->route('dpc-proposals.verification', $dpcProposal)->withErrors(['representation' => $e->getMessage()]);
        }

        return redirect()->to(route('dpc-proposals.verification', $dpcProposal).'#verification-representations')
            ->with('status', __('dpc.representations.uploaded'));
    }

    public function downloadRepresentation(DpcProposal $dpcProposal, DpcProposalRepresentation $representation): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        abort_unless($representation->dpc_proposal_id === $dpcProposal->id, 404);

        return Storage::disk($representation->disk)->download($representation->path, $representation->original_name);
    }

    public function store(ConfirmProposalVerificationRequest $request, DpcProposal $dpcProposal, string $item): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);
        $verificationItem = $this->item($item);

        try {
            $this->verification->confirm(
                $dpcProposal,
                $verificationItem,
                $request->user(),
                $request->input('remarks'),
                [
                    'basis' => $request->input('previous_dpc_basis'),
                    'proposal_id' => $request->filled('previous_dpc_proposal_id') ? $request->integer('previous_dpc_proposal_id') : null,
                    'reference' => $request->input('previous_dpc_reference'),
                    'held_on' => $request->input('previous_dpc_held_on'),
                    'record_id' => $request->filled('previous_dpc_record_id') ? $request->integer('previous_dpc_record_id') : null,
                ],
                $request->file('minutes'),
                $request->input('rr_notified_on'),
            );
        } catch (WorkflowException $e) {
            return redirect()->route('dpc-proposals.verification', $dpcProposal)
                ->withErrors(['workflow' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('dpc-proposals.verification', $dpcProposal)
            ->with('status', __('dpc.verification.confirmed', ['item' => $verificationItem->label()]));
    }

    public function destroy(Request $request, DpcProposal $dpcProposal, string $item): RedirectResponse
    {
        $this->authorize('update', $dpcProposal);
        $verificationItem = $this->item($item);

        try {
            $this->verification->withdraw($dpcProposal, $verificationItem, $request->user());
        } catch (WorkflowException $e) {
            return redirect()->route('dpc-proposals.verification', $dpcProposal)
                ->withErrors(['workflow' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.verification', $dpcProposal)
            ->with('status', __('dpc.verification.withdrawn', ['item' => $verificationItem->label()]));
    }

    /**
     * The minutes of an offline previous DPC, streamed from the private disk.
     */
    public function download(DpcProposal $dpcProposal, DpcProposalVerification $verification): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        abort_unless($verification->dpc_proposal_id === $dpcProposal->id, 404);
        abort_unless($verification->hasDocument(), 404);

        $disk = Storage::disk((string) $verification->disk);

        abort_unless($disk->exists((string) $verification->path), 404);

        return $disk->download((string) $verification->path, (string) $verification->original_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function item(string $item): ProposalVerificationItem
    {
        return ProposalVerificationItem::tryFrom($item) ?? abort(404);
    }
}
