<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Proceeding\Services\SittingMeetingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calculate is the DPC Chairman's, once every member has agreed (2026-11-18). A test
 * about the calculation itself records that state, rather than walking the sitting.
 */
trait AgreesAsCommittee
{
    /**
     * Every serving member with an account records Agree; with no allotment, `$chairman`
     * is made the DPC Chairman on their seat (the allottee is, once there is one).
     */
    protected function everyMemberAgrees(DpcProposal $proposal, ?User $chairman = null): void
    {
        // With the committee: approved, as a DPC is when its committee sits.
        if (! in_array($proposal->status, [DpcProposalStatus::Approved], true)) {
            $proposal->forceFill(['status' => DpcProposalStatus::Approved->value])->save();
        }

        // Every member has a login account (2026-11-18): a seat recorded without one gets one.
        foreach (DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->whereNull('user_id')->get() as $seat) {
            $seat->forceFill(['user_id' => User::factory()->create(['name' => $seat->name])->id])->save();
        }

        $seats = DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->whereNotNull('user_id')->get();

        if ($chairman !== null) {
            DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->update(['is_dpc_chairman' => null]);
            $seats->firstWhere('user_id', $chairman->id)?->forceFill(['is_dpc_chairman' => 1])->save();
        }

        // Each member agrees on every candidate (2026-11-18), the DPC Chairman too, seat or none (2026-11-27).
        $meetings = app(SittingMeetingService::class);
        foreach ($seats->pluck('user_id')->merge($meetings->requiredMemberIds($proposal))->unique() as $userId) {
            foreach ($meetings->candidatesAwaitingOk($proposal, (int) $userId) as $rowId) {
                DpcCandidateReview::query()->create([
                    'dpc_proposal_id' => $proposal->id, 'dpc_evaluation_row_id' => $rowId, 'user_id' => $userId, 'reviewed_at' => now(),
                ]);
            }
        }

        // Calculate is offered only at the sitting itself (2026-11-24): the committee sits now.
        $this->sittingNow($proposal);

        foreach ($seats->pluck('user_id')->unique() as $userId) {
            DpcCommitteeDecision::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'user_id' => $userId,
                'member_name' => 'Member',
                'decision' => DpcCommitteeDecision::AGREED,
                'decided_at' => now(),
            ]);
        }
    }

    /** Every member records OK on every candidate still awaiting theirs (2026-11-18). */
    protected function everyMemberOksEveryCandidate(DpcProposal $proposal): void
    {
        $meetings = app(SittingMeetingService::class);
        foreach ($meetings->requiredMemberIds($proposal) as $userId) {
            foreach ($meetings->candidatesAwaitingOk($proposal, $userId) as $rowId) {
                DpcCandidateReview::query()->create([
                    'dpc_proposal_id' => $proposal->id, 'dpc_evaluation_row_id' => $rowId, 'user_id' => $userId, 'reviewed_at' => now(),
                ]);
            }
        }
    }

    /**
     * The DPC's sitting is now (2026-11-24: Calculate only on its day, from its hour): its
     * current allotment's schedule, moved to the present minute when it is not open.
     */
    protected function sittingNow(DpcProposal $proposal): void
    {
        $allotment = DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->latest('id')->first();
        if ($allotment === null || app(CommitteeSittingService::class)->callWindowIsOpen($proposal)) {
            return;
        }

        $current = DpcSchedule::query()->where('dpc_allotment_id', $allotment->id)->current()->pluck('id');
        $at = now()->copy()->startOfMinute();
        $schedule = DpcSchedule::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'dpc_allotment_id' => $allotment->id,
            'dpc_date' => $at->toDateString(),
            'dpc_time' => $at->format('H:i'),
            'scheduled_by' => $allotment->allotted_to,
            'scheduled_at' => now(),
        ]);
        DB::table('dpc_schedules')->whereIn('id', $current)->update(['superseded_by_id' => $schedule->id]);
    }
}
