<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opening a DPC's candidate verification on the AH's approval (2026-11-19).
 *
 * The days are the Administrator's for the stage (Workflow Stage Timelines), read at
 * the approval and kept on the cycle with the deadline they give: the approval's
 * date plus the days, to the end of that calendar day in the application's timezone.
 * A later change of the days never moves an open cycle's deadline.
 *
 * One cycle per DPC: a DPC returned and approved again goes straight to the Secretary
 * the second time — a candidate submits grievances once per DPC.
 */
final class VerificationCycleService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly VerificationNotifier $notifier,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('dpc.candidate_verification.enabled', true);
    }

    /** Whether this DPC's approval opens the verification: on, and not yet run for it. */
    public function opensOn(DpcProposal $proposal): bool
    {
        return $this->enabled() && VerificationCycle::query()->where('dpc_proposal_id', $proposal->id)->doesntExist();
    }

    /**
     * Refused before anything is signed: the stage has no days set.
     *
     * @throws WorkflowException
     */
    public function assertCanOpen(DpcProposal $proposal): void
    {
        if ($this->opensOn($proposal) && WorkflowStageTimeline::daysFor(DpcStage::CandidateVerification) === null) {
            throw new WorkflowException((string) __('candidate.verification.errors.no_days'));
        }
    }

    /** The deadline days give from a moment: the end of that day, days later. */
    public static function deadlineFrom(Carbon $approvedAt, int $days): Carbon
    {
        return $approvedAt->copy()->timezone((string) config('app.timezone'))->startOfDay()->addDays($days)->endOfDay();
    }

    /**
     * Open the cycle, inside the approval's transaction; the messages go after it commits.
     *
     * @throws WorkflowException
     */
    public function open(DpcProposal $proposal, ?User $by): VerificationCycle
    {
        $days = WorkflowStageTimeline::daysFor(DpcStage::CandidateVerification)
            ?? throw new WorkflowException((string) __('candidate.verification.errors.no_days'));
        $approvedAt = now();

        $cycle = VerificationCycle::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'status' => CycleStatus::Open->value,
            'approved_at' => $approvedAt,
            'started_by' => $by?->id,
            'duration_days' => $days,
            'deadline_at' => self::deadlineFrom($approvedAt, $days),
        ]);

        // Every officer on the DPC's Seniority List.
        $rows = $proposal->evaluationRows()->orderBy('sn')->get();
        foreach ($rows as $row) {
            VerificationParticipant::query()->create([
                'dpc_verification_cycle_id' => $cycle->id,
                'dpc_evaluation_row_id' => $row->id,
                'status' => ParticipantStatus::NotStarted->value,
            ]);
        }
        $cycle->forceFill(['participant_count' => $rows->count()])->save();

        $this->timeline->record('dpc_proposal.candidate_verification_opened', $proposal, sprintf(
            'Candidate verification opened for %d officer(s), %d day(s), until %s',
            $rows->count(), $days, $cycle->deadline_at->format('d-m-Y H:i'),
        ), ['days' => $days, 'deadline_at' => $cycle->deadline_at->toIso8601String(), 'participants' => $rows->count()], $by);

        DB::afterCommit(fn () => $this->announce($cycle));

        return $cycle;
    }

    /** Every candidate is told, once (SMS and e-mail where held). */
    public function announce(VerificationCycle $cycle): void
    {
        $cycle->loadMissing('proposal');
        $values = [
            'dpc' => CandidateRecordView::reference($cycle->proposal),
            'deadline' => $cycle->deadline_at->format('d-m-Y'),
            'url' => route('candidate-verification.login'),
        ];

        foreach ($cycle->participants()->with('row.proposal', 'cycle')->get() as $participant) {
            $this->notifier->toParticipant($participant, 'opened', $values);
        }
    }
}
