<?php

declare(strict_types=1);

namespace App\Http\SignedActions;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\CandidateVerification\Services\GrievanceReviewService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Contracts\SignedAction;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A candidate's grievance corrections approved, eSigned by the AH (2026-11-24): each
 * grievance the AO sent back is resolved — upheld, the record corrected — and the
 * candidate is told. Payload: proposal (uuid), participant (uuid), remark.
 */
class ApproveGrievanceCorrections implements SignedAction
{
    public function __construct(private readonly GrievanceReviewService $reviews) {}

    public function perform(Request $request, User $signer, array $payload): RedirectResponse
    {
        $proposal = DpcProposal::query()->where('uuid', (string) $payload['proposal'])->firstOrFail();
        $participant = VerificationParticipant::query()->where('uuid', (string) $payload['participant'])->firstOrFail();
        abort_unless($participant->cycle->dpc_proposal_id === $proposal->id, 404);
        $back = route('dpc-proposals.grievances.index', $proposal).'#candidate-'.$participant->row->sn;

        try {
            $count = $this->reviews->approveCandidate($participant, (string) $payload['remark'], $signer, $request);
        } catch (WorkflowException|SignatureVerificationFailed $e) {
            return redirect()->to($back)->withErrors(['grievance' => $e->getMessage()]);
        }

        return redirect()->to($back)->with('status', __('candidate.grievance.review.approved_done', ['count' => $count, 'name' => $participant->row->name]));
    }

    public function abandon(array $payload): RedirectResponse
    {
        $proposal = DpcProposal::query()->where('uuid', (string) $payload['proposal'])->firstOrFail();

        return redirect()->route('dpc-proposals.grievances.index', $proposal);
    }
}
