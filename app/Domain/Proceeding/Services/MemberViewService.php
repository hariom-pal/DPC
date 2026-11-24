<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The committee members' view of an allotted DPC (2026-11-24).
 *
 * Once the CGPSC Chairman allots a DPC, its DPC Chairman (the allottee) sees it; the
 * other members see nothing of it until the DPC Chairman opens it to them ("view
 * mode"). The DPC Chairman has the days the Administrator sets for the Committee View
 * stage (Workflow Stage Timelines; two days — 48 hours — when none are set), counted
 * from the allotment; once they pass, it is open to the members on its own.
 *
 * The opening is recorded against the allotment it was made under, so a re-allotment
 * to somebody else starts afresh with nothing to clear.
 */
final class MemberViewService
{
    public const EVENT_ACTIVATED = 'dpc_proposal.member_view_activated';

    public const EVENT_AUTO = 'dpc_proposal.member_view_auto_activated';

    /** Two days (48 hours) when the Administrator has set none. */
    public const DEFAULT_DAYS = 2;

    public function __construct(private readonly ActivityTimelineService $timeline) {}

    public function allotment(DpcProposal $proposal): ?DpcAllotment
    {
        return DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->latest('id')->first();
    }

    /** The days the DPC Chairman has to open it to the members. */
    public function days(): int
    {
        return max(1, WorkflowStageTimeline::daysFor(DpcStage::CommitteeView) ?? self::DEFAULT_DAYS);
    }

    /** When it opens to the members on its own, or null before an allotment. */
    public function deadline(DpcProposal $proposal): ?Carbon
    {
        $allotment = $this->allotment($proposal);

        return $allotment === null ? null : $allotment->allotted_at->copy()->addDays($this->days());
    }

    /** Whether the DPC Chairman opened it under the current allotment. */
    public function activatedManually(DpcProposal $proposal): bool
    {
        $allotment = $this->allotment($proposal);

        return $allotment !== null
            && $proposal->member_view_enabled_at !== null
            && $proposal->member_view_enabled_by !== null
            && (int) $proposal->member_view_allotment_id === (int) $allotment->id;
    }

    /** Whether the committee members may see the DPC now. */
    public function isActive(DpcProposal $proposal, ?Carbon $now = null): bool
    {
        $allotment = $this->allotment($proposal);
        if ($allotment === null) {
            return false;
        }

        if ($proposal->member_view_enabled_at !== null && (int) $proposal->member_view_allotment_id === (int) $allotment->id) {
            return true;
        }

        $deadline = $allotment->allotted_at->copy()->addDays($this->days());

        return ($now ?? now())->greaterThanOrEqualTo($deadline);
    }

    /** Whether this user may open it to the members now: the DPC Chairman, while it is still closed to them. */
    public function mayActivate(DpcProposal $proposal, ?User $user): bool
    {
        $allotment = $this->allotment($proposal);

        return $user !== null && $allotment !== null && (int) $allotment->allotted_to === (int) $user->id && ! $this->isActive($proposal);
    }

    /** @throws WorkflowException */
    public function activate(DpcProposal $proposal, User $chairman): void
    {
        DB::transaction(function () use ($proposal, $chairman): void {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayActivate($locked, $chairman)) {
                throw new WorkflowException((string) __('proceeding.member_view.not_yours'));
            }

            $allotment = $this->allotment($locked);
            $locked->forceFill([
                'member_view_allotment_id' => $allotment?->id,
                'member_view_enabled_at' => now(),
                'member_view_enabled_by' => $chairman->id,
            ])->save();

            $this->timeline->record(self::EVENT_ACTIVATED, $locked, (string) __('proceeding.member_view.timeline_activated', ['name' => $chairman->name]), [
                'allotment_id' => $allotment?->id,
            ], $chairman);
        });
    }

    /**
     * Record, once, each DPC opened to its members on its own because the DPC Chairman's
     * days passed. Run by the scheduler; the opening itself never waits for it.
     */
    public function recordAutoActivations(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;

        DpcAllotment::query()->current()->with('proposal')->orderBy('id')->each(function (DpcAllotment $allotment) use ($now, &$count): void {
            $proposal = $allotment->proposal;
            if ($proposal === null || (int) $proposal->member_view_allotment_id === (int) $allotment->id) {
                return;
            }

            $deadline = $allotment->allotted_at->copy()->addDays($this->days());
            if ($now->lessThan($deadline)) {
                return;
            }

            DB::transaction(function () use ($proposal, $allotment, $deadline): void {
                $proposal->forceFill([
                    'member_view_allotment_id' => $allotment->id,
                    'member_view_enabled_at' => $deadline,
                    'member_view_enabled_by' => null,
                ])->save();

                $this->timeline->record(self::EVENT_AUTO, $proposal, (string) __('proceeding.member_view.timeline_auto', ['date' => $deadline->format('d-m-Y H:i')]), [
                    'allotment_id' => $allotment->id,
                    'automatic' => true,
                ]);
            });
            $count++;
        });

        return $count;
    }
}
