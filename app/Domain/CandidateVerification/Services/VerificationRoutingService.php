<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The verification's deadline (2026-11-19): candidates who submitted nothing are No
 * Response (never agreement), and the DPC goes on — to the Secretary when no grievance
 * was submitted, to the AH's grievance review when any was. Run by the scheduler;
 * safe to run again and again: a cycle is closed once, under its lock, and a
 * submission finishing at the same moment either locked the cycle first (and counts)
 * or finds it closed (and is refused).
 */
final class VerificationRoutingService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly VerificationNotifier $notifier,
    ) {}

    /** Close every cycle whose deadline has passed; how many were closed. */
    public function closeExpired(?Carbon $now = null): int
    {
        $now ??= now();
        $closed = 0;

        VerificationCycle::query()->where('status', CycleStatus::Open->value)->where('deadline_at', '<', $now)->orderBy('id')
            ->each(function (VerificationCycle $cycle) use ($now, &$closed): void {
                if ($this->close($cycle, $now)) {
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * Every candidate has submitted before the deadline (2026-11-22): nothing is left
     * to wait for, so the verification closes now, routed as at the deadline — to the
     * AH when any grievance was raised, to the Secretary when none was.
     */
    public function closeWhenAllResponded(VerificationCycle $cycle, ?Carbon $now = null): bool
    {
        return $this->close($cycle, $now, early: true);
    }

    public function close(VerificationCycle $cycle, ?Carbon $now = null, bool $early = false): bool
    {
        $now ??= now();

        $done = DB::transaction(function () use ($cycle, $now, $early): ?array {
            /** @var VerificationCycle|null $locked */
            $locked = VerificationCycle::query()->whereKey($cycle->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== CycleStatus::Open) {
                return null;
            }
            if ($early) {
                $waiting = VerificationParticipant::query()->where('dpc_verification_cycle_id', $locked->id)->whereNull('submitted_at')->exists();
                $any = VerificationParticipant::query()->where('dpc_verification_cycle_id', $locked->id)->exists();
                // Past the deadline the scheduler closes it, as the deadline's close.
                if ($waiting || ! $any || $now->gt($locked->deadline_at)) {
                    return null;
                }
            } elseif ($now->lte($locked->deadline_at)) {
                return null;
            }

            /** @var DpcProposal $proposal */
            $proposal = DpcProposal::query()->whereKey($locked->dpc_proposal_id)->lockForUpdate()->firstOrFail();

            $noResponse = VerificationParticipant::query()->where('dpc_verification_cycle_id', $locked->id)->whereNull('submitted_at')
                ->update(['status' => ParticipantStatus::NoResponse->value, 'updated_at' => now()]);
            $grievances = Grievance::query()->where('dpc_verification_cycle_id', $locked->id)->where('status', '!=', GrievanceStatus::Draft->value)->count();

            $toReview = $grievances > 0;
            $locked->forceFill([
                'status' => ($toReview ? CycleStatus::GrievanceReview : CycleStatus::Completed)->value,
                'closed_at' => $now,
                'routed_to' => $toReview ? 'grievance_review' : 'secretary',
                'routed_at' => $now,
                'grievance_count' => $grievances,
            ])->save();

            // The DPC moves only from where the verification left it.
            if ($proposal->status === DpcProposalStatus::CandidateVerification) {
                $to = $toReview ? DpcProposalStatus::GrievanceReview : DpcProposalStatus::PendingSecretary;
                $event = $toReview ? 'verification_grievances' : 'verification_closed';
                $remarks = $early
                    ? (string) __('candidate.verification.auto.'.$event.'_early', ['date' => $now->format('d-m-Y'), 'count' => $grievances])
                    : (string) __('candidate.verification.auto.'.$event, ['date' => $locked->deadline_at->format('d-m-Y'), 'count' => $grievances, 'no_response' => $noResponse]);
                $reason = $early ? 'all_candidates_responded' : 'candidate_verification_deadline';
                $proposal->forceFill(['status' => $to->value])->save();

                WorkflowTransition::query()->create([
                    'workflow' => 'dpc_proposal', 'subject_type' => $proposal->getMorphClass(), 'subject_id' => $proposal->getKey(),
                    'event' => $event, 'from_state' => DpcProposalStatus::CandidateVerification->value, 'to_state' => $to->value,
                    'actor_id' => null, 'remarks' => $remarks,
                    'meta' => ['automatic' => true, 'reason' => $reason, 'grievances' => $grievances, 'no_response' => $noResponse],
                    'occurred_at' => now(),
                ]);
                $this->timeline->record('dpc_proposal.'.$event, $proposal, $remarks, ['automatic' => true, 'reason' => $reason, 'grievances' => $grievances, 'no_response' => $noResponse]);
            }

            return ['cycle' => $locked, 'proposal' => $proposal, 'review' => $toReview, 'count' => $grievances];
        });

        if ($done === null) {
            return false;
        }

        DB::afterCommit(function () use ($done): void {
            $reference = CandidateRecordView::reference($done['proposal']);
            if ($done['review']) {
                $this->notifier->toUsers($done['cycle'], $this->allotted($done['proposal'], Role::AdministrativeHead), 'grievance_review', [
                    'dpc' => $reference, 'count' => (string) $done['count'], 'url' => route('dpc-proposals.grievances.index', $done['proposal']),
                ]);
            } else {
                $this->notifySecretary($done['cycle'], $done['proposal']);
            }
        });

        return true;
    }

    public function notifySecretary(VerificationCycle $cycle, DpcProposal $proposal): void
    {
        $secretaries = User::role(Role::Secretary->value)->where('is_active', true)->get();
        $this->notifier->toUsers($cycle, $secretaries, 'secretary_ready', [
            'dpc' => CandidateRecordView::reference($proposal), 'url' => route('dpc-proposals.show', $proposal),
        ]);
    }

    /** @return iterable<User> */
    public function allotted(DpcProposal $proposal, Role $role): iterable
    {
        $allotment = $proposal->currentAllotment;
        $id = $role === Role::AdministrativeHead ? $allotment?->ah_user_id : $allotment?->ao_user_id;

        return $id === null ? [] : User::query()->whereKey($id)->where('is_active', true)->get();
    }
}
