<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/**
 * Where a DPC stands, and the AO's mark that it is over (MoM of 12 November 2026,
 * points 2 and 4).
 *
 * **Phases**, each with its colour:
 * - `closed` (red): the AO marked the DPC Proceeding Complete, or it was cancelled;
 * - `pending` (green): action is due at the department — the AO or AH is preparing,
 *   approving or correcting it, or, once CGPSC has approved the result, issuing the
 *   promotion orders and marking it complete;
 * - `with_cgpsc` (blue): the AO and AH have done their part and the proceeding is
 *   with CGPSC.
 *
 * **The mark.** Only the DPC's allotted AO, only once CGPSC has approved the result
 * and a promotion order is issued, and only once: the DPC is then Closed for good.
 */
class DpcCompletionService
{
    public const CLOSED = 'closed';

    public const PENDING = 'pending';

    public const WITH_CGPSC = 'with_cgpsc';

    public function __construct(
        private readonly ProposalAllotmentService $allotments,
        private readonly ActivityTimelineService $timeline,
    ) {}

    public function phase(DpcProposal $proposal): string
    {
        if ($proposal->status === DpcProposalStatus::Cancelled || $proposal->proceeding_completed_at !== null) {
            return self::CLOSED;
        }

        if ($proposal->status !== DpcProposalStatus::Approved) {
            return self::PENDING;
        }

        $proceeding = $this->proceeding($proposal);

        if ($proceeding?->cancelled_at !== null) {
            return self::CLOSED;
        }

        // The result approved: the promotion orders and the mark are the department's.
        return $proceeding?->closed_at !== null ? self::PENDING : self::WITH_CGPSC;
    }

    public static function badgeClass(string $phase): string
    {
        return match ($phase) {
            self::CLOSED => 'text-bg-danger',
            self::PENDING => 'text-bg-success',
            default => 'text-bg-primary',
        };
    }

    /**
     * Why the AO may not mark this DPC complete now, or null when they may.
     */
    public function refusal(DpcProposal $proposal, ?User $user): ?string
    {
        return match (true) {
            $proposal->proceeding_completed_at !== null => (string) __('dpc.completion.errors.already'),
            ! $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer) => (string) __('dpc.completion.errors.not_ao'),
            $proposal->status !== DpcProposalStatus::Approved || $this->proceeding($proposal)?->closed_at === null => (string) __('dpc.completion.errors.result_pending'),
            ! $this->hasIssuedOrder($proposal) => (string) __('dpc.completion.errors.no_order'),
            default => null,
        };
    }

    public function complete(DpcProposal $proposal, User $actor): DpcProposal
    {
        return DB::transaction(function () use ($proposal, $actor): DpcProposal {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $refusal = $this->refusal($locked, $actor);

            if ($refusal !== null) {
                throw new WorkflowException($refusal);
            }

            $locked->forceFill(['proceeding_completed_at' => now(), 'proceeding_completed_by' => $actor->id])->save();

            $this->timeline->record('dpc_proposal.proceeding_completed', $locked, (string) __('dpc.completion.timeline', ['name' => $actor->name]), [
                'proceeding_completed_at' => $locked->proceeding_completed_at?->toIso8601String(),
            ], $actor);

            return $locked;
        });
    }

    private function proceeding(DpcProposal $proposal): ?DpcProceeding
    {
        return DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->latest('id')->first();
    }

    private function hasIssuedOrder(DpcProposal $proposal): bool
    {
        return DpcPromotionOrder::query()->where('dpc_proposal_id', $proposal->id)->where('status', DpcPromotionOrder::STATUS_ISSUED)->exists();
    }
}
