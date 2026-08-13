<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Member acceptance and DPC scheduling (PPT S30) — M6 Phase 2.
 *
 * Continues the `dpc_cgpsc` workflow begun by `AllotmentService`, on the same
 * subject and with the same discipline: the state is derived from the current
 * allotment, never stored on the proposal, and every transition records a
 * timeline entry rich enough to reconstruct it without reading these tables.
 *
 * **The scheduled date is not validated against the allotment's default DPC
 * date** (architect Decision 4). The default is informational until CGPSC
 * specifies a rule, and inventing one here would be inventing a business rule.
 */
class SchedulingService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly AllotmentService $allotments,
    ) {}

    /**
     * S30 — the allottee accepts the DPC.
     *
     * There is no decline (architect Decision 3): a member who cannot proceed
     * is handled by the Chairman re-allotting.
     */
    public function accept(DpcProposal $proposal, User $member, Request $request): DpcAllotment
    {
        $allotment = $this->assertAllottee($proposal, $member);

        if ($allotment->status !== AllotmentStatus::Pending) {
            throw new WorkflowException('This DPC has already been accepted.');
        }

        $from = $this->allotments->state($proposal);
        $def = $this->engine->assertAllowed('dpc_cgpsc', 'accept', $from, $member);

        return DB::transaction(function () use ($proposal, $allotment, $member, $request, $def, $from): DpcAllotment {
            $allotment->update([
                'status' => AllotmentStatus::Accepted->value,
                'accepted_at' => now(),
            ]);

            $this->engine->record(
                $proposal, 'dpc_cgpsc', 'accept', $from, $def, $member, $request,
                null, null, $proposal->department_id,
                [
                    'allotment_uuid' => $allotment->uuid,
                    'accepted_by_id' => $member->id,
                    'accepted_by_name' => $member->name,
                    'accepted_at' => now()->toIso8601String(),
                ],
                [$member->id],
            );

            return $allotment->refresh();
        });
    }

    /**
     * S30 — the allottee sets the DPC date and time.
     */
    public function schedule(
        DpcProposal $proposal,
        User $member,
        Request $request,
        Carbon $date,
        string $time,
    ): DpcSchedule {
        $allotment = $this->assertAllottee($proposal, $member);

        if ($allotment->status !== AllotmentStatus::Accepted) {
            throw new WorkflowException('This DPC must be accepted before it can be scheduled.');
        }

        $from = $this->allotments->state($proposal);
        $def = $this->engine->assertAllowed('dpc_cgpsc', 'schedule', $from, $member);

        return DB::transaction(function () use ($proposal, $allotment, $member, $request, $def, $from, $date, $time): DpcSchedule {
            $schedule = DpcSchedule::query()->create([
                'dpc_allotment_id' => $allotment->id,
                'dpc_date' => $date->toDateString(),
                // Normalised on write: MySQL's TIME column returns H:i:s while
                // SQLite returns whatever string was stored, and a schedule
                // that reads differently per driver is a schedule you cannot
                // compare or export reliably.
                'dpc_time' => Carbon::parse($time)->format('H:i:s'),
                'scheduled_by' => $member->id,
                'scheduled_at' => now(),
            ]);

            $allotment->update(['status' => AllotmentStatus::Scheduled->value]);

            $this->engine->record(
                $proposal, 'dpc_cgpsc', 'schedule', $from, $def, $member, $request,
                null, null, $proposal->department_id,
                // Enough for an export, a certificate, or a future calendar
                // subscriber to act without reading a business table.
                [
                    'allotment_uuid' => $allotment->uuid,
                    'schedule_uuid' => $schedule->uuid,
                    'dpc_date' => $schedule->dpc_date->toDateString(),
                    'dpc_time' => $schedule->dpc_time,
                    'scheduled_for' => $schedule->scheduledFor()->toIso8601String(),
                    'timezone' => (string) config('dpc.timezone'),
                    'scheduled_by_id' => $member->id,
                    'scheduled_by_name' => $member->name,
                    'default_dpc_date' => $allotment->default_dpc_date?->toDateString(),
                ],
                [$member->id],
            );

            return $schedule;
        });
    }

    /**
     * S30 — RE-SCHEDULING. The allottee moves the DPC to a new date and time.
     *
     * Append-only, like every other history in this milestone: a new row is
     * written and the previous one marked superseded. S30 shows the old date
     * struck through beside the new, which is only possible because the old
     * row survives.
     */
    public function reschedule(
        DpcProposal $proposal,
        User $member,
        Request $request,
        Carbon $date,
        string $time,
        string $reason,
    ): DpcSchedule {
        $allotment = $this->assertAllottee($proposal, $member);
        $existing = $this->current($proposal);

        if ($existing === null) {
            throw new WorkflowException('This DPC has not been scheduled yet, so it cannot be re-scheduled.');
        }

        $normalisedTime = Carbon::parse($time)->format('H:i:s');

        if ($existing->dpc_date->toDateString() === $date->toDateString() && $existing->dpc_time === $normalisedTime) {
            throw new WorkflowException('That is already the scheduled date and time.');
        }

        $from = $this->allotments->state($proposal);
        $def = $this->engine->assertAllowed('dpc_cgpsc', 'reschedule', $from, $member);

        return DB::transaction(function () use ($proposal, $allotment, $existing, $member, $request, $def, $from, $date, $normalisedTime, $reason): DpcSchedule {
            $schedule = DpcSchedule::query()->create([
                'dpc_allotment_id' => $allotment->id,
                'dpc_date' => $date->toDateString(),
                'dpc_time' => $normalisedTime,
                'scheduled_by' => $member->id,
                'scheduled_at' => now(),
                'reschedule_reason' => $reason,
            ]);

            // Superseded, never edited — the chain is the history.
            $existing->update(['superseded_by_id' => $schedule->id]);

            $this->engine->record(
                $proposal, 'dpc_cgpsc', 'reschedule', $from, $def, $member, $request,
                $reason, null, $proposal->department_id,
                [
                    'allotment_uuid' => $allotment->uuid,
                    'schedule_uuid' => $schedule->uuid,
                    'dpc_date' => $schedule->dpc_date->toDateString(),
                    'dpc_time' => $schedule->dpc_time,
                    'scheduled_for' => $schedule->scheduledFor()->toIso8601String(),
                    'timezone' => (string) config('dpc.timezone'),
                    'scheduled_by_id' => $member->id,
                    'scheduled_by_name' => $member->name,
                    // The superseded values, so old-beside-new is reproducible
                    // from the timeline without reading a business table.
                    'superseded_schedule_uuid' => $existing->uuid,
                    'previous_dpc_date' => $existing->dpc_date->toDateString(),
                    'previous_dpc_time' => $existing->dpc_time,
                ],
                [$member->id],
            );

            return $schedule;
        });
    }

    /**
     * The live schedule for a proposal, or null if none has been set.
     */
    public function current(DpcProposal $proposal): ?DpcSchedule
    {
        $allotment = $this->allotments->current($proposal);

        if ($allotment === null) {
            return null;
        }

        return DpcSchedule::query()
            ->where('dpc_allotment_id', $allotment->id)
            ->current()
            ->latest('scheduled_at')
            ->first();
    }

    /**
     * Scheduling history for a proposal, read **only** from timeline records.
     *
     * The timeline is the legal workflow history (architect ruling); business
     * tables hold current state. This method touches no schedule row, so if
     * the timeline could not answer the question it would visibly fail rather
     * than quietly fall back.
     *
     * @return Collection<int, ActivityTimeline>
     */
    public function historyFromTimeline(DpcProposal $proposal)
    {
        return ActivityTimeline::query()
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->whereIn('event', ['dpc_cgpsc.accept', 'dpc_cgpsc.schedule', 'dpc_cgpsc.reschedule'])
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();
    }

    /**
     * Holding the Member role is not the same as being *this* DPC's member.
     * The workflow config gates the role; this gates the individual.
     */
    private function assertAllottee(DpcProposal $proposal, User $member): DpcAllotment
    {
        $allotment = $this->allotments->current($proposal);

        if ($allotment === null) {
            throw new WorkflowException('This DPC has not been allotted yet.');
        }

        if ($allotment->allotted_to !== $member->id) {
            throw new WorkflowException('This DPC is allotted to another member.');
        }

        return $allotment;
    }
}
