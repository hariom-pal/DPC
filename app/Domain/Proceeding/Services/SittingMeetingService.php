<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Models\DpcSittingMeeting;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The DPC sitting as a meeting, and each member's Agree (business decisions of
 * 2026-11-18 and 2026-11-25).
 *
 * Candidates are never called before a DPC. The DPC Chairman starts the sitting's
 * meeting — the portal makes the room on the Commission's meeting server — and every
 * other member is sent a link to join it through the portal. Each member agrees once
 * with the whole DPC, which is recorded against every candidate, and may then disagree
 * on any candidate with a remark for the DPC Chairman. An Agree stops counting when the
 * record it was given on changes (a correction at the sitting, or a return
 * resubmitted), and is given again on what changed.
 *
 * The CGPSC Chairman's own seat is not among the members whose OK and Agree are
 * awaited: that office allots the DPC, and the allottee presides.
 */
final class SittingMeetingService
{
    /** Where the public Jitsi server may be used: development and the test suite only. */
    private const PUBLIC_SERVER_ENVIRONMENTS = ['local', 'testing'];

    private const PUBLIC_SERVER = 'https://meet.jit.si';

    public function __construct(
        private readonly SealedCoverService $covers,
        private readonly CommitteeSittingService $sittings,
        private readonly MessageTemplates $templates,
        private readonly ActivityTimelineService $timeline,
    ) {}

    // ---------------------------------------------------------------- meeting

    /** The meeting now running for this DPC, if one was started. */
    public function current(DpcProposal $proposal): ?DpcSittingMeeting
    {
        return DpcSittingMeeting::query()->where('dpc_proposal_id', $proposal->id)->latest('initiated_at')->latest('id')->first();
    }

    /** Whether this user may start the meeting: the DPC Chairman, while the DPC is with the committee. */
    public function mayInitiate(DpcProposal $proposal, ?User $user): bool
    {
        // Once the DPC Chairman has accepted the DPC (2026-11-18).
        return $proposal->status === DpcProposalStatus::Approved && $this->covers->isDpcChairman($proposal, $user)
            && app(CalculationGate::class)->chairmanAccepted($proposal);
    }

    /**
     * Whether the sitting is on: the day the DPC was scheduled for, from its hour
     * (the members join and OK the candidates then, together).
     */
    public function sittingOpen(DpcProposal $proposal): bool
    {
        return $this->sittings->callWindowIsOpen($proposal);
    }

    /** Why a member cannot agree or disagree now, or null when they can. */
    public function okRefusal(DpcProposal $proposal): ?string
    {
        if (! app(CalculationGate::class)->chairmanAccepted($proposal)) {
            return (string) __('proceeding.sitting.ok_after_accept');
        }

        return $this->sittingOpen($proposal) ? null : (string) $this->sittings->callWindowReason($proposal);
    }

    /**
     * The meeting server the rooms are made on: the Commission's own, from
     * DPC_MEETING_BASE_URL. The public meet.jit.si only for development and tests;
     * anywhere else, no server configured means no meeting.
     */
    public function serverUrl(): ?string
    {
        $configured = trim((string) config('dpc.meeting.base_url'));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return app()->environment(self::PUBLIC_SERVER_ENVIRONMENTS) ? self::PUBLIC_SERVER : null;
    }

    /**
     * Start the meeting: a room on the meeting server, recorded, and a link to join it
     * through the portal sent to every other member with a login.
     *
     * @throws WorkflowException
     */
    public function initiate(DpcProposal $proposal, User $chairman): DpcSittingMeeting
    {
        if (! $this->mayInitiate($proposal, $chairman)) {
            throw new WorkflowException((string) __('proceeding.sitting.meeting_only_chairman'));
        }

        $server = $this->serverUrl();
        if ($server === null) {
            throw new WorkflowException((string) __('proceeding.sitting.meeting_server_missing'));
        }

        // Unguessable: the room is reached through the portal, never listed.
        $room = 'eDPC-'.Str::random(32);
        $meeting = DpcSittingMeeting::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'room' => $room,
            'url' => $server.'/'.$room,
            'initiated_by' => $chairman->id,
            'initiated_at' => now(),
        ]);

        $this->timeline->record('dpc_sitting.meeting_initiated', $proposal, sprintf('DPC meeting started by %s', $chairman->name), [
            'meeting_uuid' => $meeting->uuid,
        ], $chairman);

        $this->notifyMembers($proposal, $chairman);

        return $meeting;
    }

    // ------------------------------------------------------ Agree and Disagree

    /**
     * The members whose OK and Agree are awaited: every serving seat with a login,
     * the CGPSC Chairman's own seat aside.
     *
     * @return list<int>
     */
    public function requiredMemberIds(DpcProposal $proposal): array
    {
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('capacity', '!=', CommitteeCapacity::CgpscChairman->value)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * This member agrees with the whole DPC (2026-11-25): one Agree covers every
     * candidate still awaiting theirs — all of them the first time; after a record
     * changed (the DPC Chairman's edit, or a resubmission), only those.
     *
     * @return int how many candidates the Agree covered
     *
     * @throws WorkflowException
     */
    public function agree(DpcProposal $proposal, User $member): int
    {
        // After the DPC Chairman accepted it, at the sitting itself (2026-11-18).
        if (($refusal = $this->okRefusal($proposal)) !== null) {
            throw new WorkflowException($refusal);
        }

        return DB::transaction(function () use ($proposal, $member): int {
            // Serialised on the DPC, so two presses at once make one Agree.
            DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            $awaiting = $this->candidatesAwaitingOk($proposal, $member->id);
            if ($awaiting === []) {
                throw new WorkflowException((string) __('proceeding.sitting.already_agreed'));
            }

            $now = now();
            foreach ($awaiting as $rowId) {
                DpcCandidateReview::query()->create([
                    'dpc_proposal_id' => $proposal->id,
                    'dpc_evaluation_row_id' => $rowId,
                    'user_id' => $member->id,
                    'reviewed_at' => $now,
                ]);
            }

            $this->timeline->record('dpc_sitting.agreed', $proposal, sprintf('%s agreed with the DPC (%d candidate(s))', $member->name, count($awaiting)), [
                'dpc_evaluation_row_ids' => $awaiting,
            ], $member);

            return count($awaiting);
        });
    }

    /**
     * This member disagrees on one candidate, with a remark for the DPC Chairman
     * (2026-11-25): only on a candidate their Agree covers, once, and never withdrawn.
     * The remark has no length limit, by instruction; it is kept off the timeline,
     * which others read — the DPC Chairman reads it on the sitting.
     *
     * @throws WorkflowException
     */
    public function disagree(DpcProposal $proposal, DpcEvaluationRow $row, User $member, string $remark): DpcCandidateReview
    {
        if (($refusal = $this->okRefusal($proposal)) !== null) {
            throw new WorkflowException($refusal);
        }

        $remark = trim($remark);
        if ($remark === '') {
            throw new WorkflowException((string) __('proceeding.sitting.disagree_remark_required'));
        }

        return DB::transaction(function () use ($proposal, $row, $member, $remark): DpcCandidateReview {
            DpcEvaluationRow::query()->whereKey($row->id)->lockForUpdate()->first();

            $review = DpcCandidateReview::query()
                ->where('dpc_evaluation_row_id', $row->id)
                ->where('user_id', $member->id)
                ->whereNull('invalidated_at')
                ->first();

            if ($review === null) {
                throw new WorkflowException((string) __('proceeding.sitting.agree_first'));
            }
            if ($review->disagreed_at !== null) {
                throw new WorkflowException((string) __('proceeding.sitting.already_disagreed'));
            }

            $review->forceFill(['disagreed_at' => now(), 'disagree_remarks' => $remark])->save();

            $this->timeline->record('dpc_sitting.candidate_disagreed', $proposal, sprintf('%s disagreed on %s', $member->name, $row->name), [
                'dpc_evaluation_row_id' => $row->id,
            ], $member);

            return $review;
        });
    }

    /**
     * Every Disagree that counts, by candidate, for the DPC Chairman (2026-11-25).
     *
     * @return Collection<int, Collection<int, DpcCandidateReview>>
     */
    public function disagreements(DpcProposal $proposal): Collection
    {
        return DpcCandidateReview::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->whereNull('invalidated_at')
            ->whereNotNull('disagreed_at')
            ->with('user')
            ->orderBy('disagreed_at')
            ->get()
            ->toBase()
            ->groupBy('dpc_evaluation_row_id');
    }

    /**
     * The OKs that count, by candidate.
     *
     * @return Collection<int, Collection<int, DpcCandidateReview>>
     */
    public function reviews(DpcProposal $proposal): Collection
    {
        return DpcCandidateReview::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->whereNull('invalidated_at')
            ->with('user')
            ->get()
            ->toBase()
            ->groupBy('dpc_evaluation_row_id');
    }

    /** How many members' OK each candidate awaits. */
    public function memberCount(DpcProposal $proposal): int
    {
        return count($this->requiredMemberIds($proposal));
    }

    /**
     * The candidates this member has not yet OK'd on their current record.
     *
     * @return list<int> evaluation row ids
     */
    public function candidatesAwaitingOk(DpcProposal $proposal, int $userId): array
    {
        $reviewed = DpcCandidateReview::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('user_id', $userId)
            ->whereNull('invalidated_at')
            ->pluck('dpc_evaluation_row_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->sittings->candidates($proposal)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => in_array($id, $reviewed, true))
            ->values()
            ->all();
    }

    /**
     * A candidate's record changed (a correction at the sitting, or its withdrawal):
     * every OK on it stops counting, and a fresh OK is needed on the updated record.
     */
    public function invalidateCandidate(DpcEvaluationRow $row, string $reason, ?User $actor = null): int
    {
        $count = DpcCandidateReview::query()->where('dpc_evaluation_row_id', $row->id)->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidation_reason' => $reason]);

        if ($count > 0 && $row->proposal !== null) {
            $this->timeline->record('dpc_sitting.candidate_ok_invalidated', $row->proposal, sprintf('Agree on %s to be given again: the record changed (%s)', $row->name, $reason), [
                'dpc_evaluation_row_id' => $row->id, 'reason' => $reason, 'count' => $count,
            ], $actor);
        }

        return $count;
    }

    /**
     * A return to the AO resubmitted: no OK given on the earlier submission is carried
     * forward unverified — every candidate is OK'd afresh on what came back.
     */
    public function invalidateProposal(DpcProposal $proposal, string $reason, ?User $actor = null): int
    {
        $count = DpcCandidateReview::query()->where('dpc_proposal_id', $proposal->id)->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidation_reason' => $reason]);

        if ($count > 0) {
            $this->timeline->record('dpc_sitting.candidate_ok_invalidated', $proposal, sprintf('Every Agree to be given again: %s', $reason), [
                'reason' => $reason, 'count' => $count,
            ], $actor);
        }

        return $count;
    }

    private function notifyMembers(DpcProposal $proposal, User $chairman): void
    {
        $schedule = $this->sittings->schedule($proposal);
        $mail = $this->templates->email('sitting.meeting', [
            'dpc' => (string) ($proposal->dpc_code ?? $proposal->citation()),
            'chairman' => $chairman->name,
            'url' => route('sitting.meeting', $proposal),
            // The day and hour the members join together: the sitting's.
            'when' => $schedule?->scheduledFor()->format('d-m-Y H:i') ?? '—',
        ]);

        if ($mail === null) {
            return;
        }

        $members = User::query()->whereIn('id', DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->whereNotNull('user_id')->pluck('user_id'))
            ->where('id', '!=', $chairman->id)
            ->get();

        foreach ($members as $member) {
            if (! $member->email) {
                continue;
            }

            try {
                Mail::raw($mail['body'], fn ($message) => $message->to((string) $member->email)->subject($mail['subject']));
            } catch (Throwable $e) {
                Log::warning('The meeting link could not be sent to one member.', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
