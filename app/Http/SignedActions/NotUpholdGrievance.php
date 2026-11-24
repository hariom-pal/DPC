<?php

declare(strict_types=1);

namespace App\Http\SignedActions;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Services\GrievanceReviewService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Contracts\SignedAction;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A grievance not upheld, eSigned by the AH (2026-11-24): the record stands, and the
 * candidate sees the reason. Payload: proposal (uuid), grievance (uuid), reason.
 */
class NotUpholdGrievance implements SignedAction
{
    public function __construct(private readonly GrievanceReviewService $reviews) {}

    public function perform(Request $request, User $signer, array $payload): RedirectResponse
    {
        $proposal = DpcProposal::query()->where('uuid', (string) $payload['proposal'])->firstOrFail();
        $grievance = Grievance::query()->where('uuid', (string) $payload['grievance'])->firstOrFail();
        abort_unless($grievance->cycle->dpc_proposal_id === $proposal->id, 404);
        $back = redirect()->route('dpc-proposals.grievances.index', $proposal).'#grievance-'.$grievance->uuid;

        try {
            $this->reviews->notUphold($grievance, (string) $payload['reason'], $signer, $request);
        } catch (WorkflowException|SignatureVerificationFailed $e) {
            return redirect()->to($back)->withErrors(['grievance' => $e->getMessage()]);
        }

        return redirect()->to($back)->with('status', __('candidate.grievance.review.not_upheld_done'));
    }

    public function abandon(array $payload): RedirectResponse
    {
        $proposal = DpcProposal::query()->where('uuid', (string) $payload['proposal'])->firstOrFail();

        return redirect()->route('dpc-proposals.grievances.index', $proposal);
    }
}
