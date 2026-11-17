<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcStageReminder;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Dpc\Services\DpcRevisionService;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Workflow Timelines, run once a day (MoM of 16 November 2026, points 6 and 7, and
 * the Administrator's per-stage choices of 2026-11-17).
 *
 * - The day before a stage's deadline, a reminder goes to whoever is to act.
 * - Once the deadline has passed, the stage's own choice applies: keep reminding
 *   (as often as set), cancel the DPC, or forward it to the next officer — the AH's
 *   approval to the Secretary, the Secretary's to the Chairman.
 * - Reminders go by e-mail and/or SMS, as set. Every reminder and every automatic
 *   action is recorded; an action has no actor (it is the system's).
 */
class DpcStageDeadlinesCommand extends Command
{
    protected $signature = 'dpc:stage-deadlines {--dry-run : Report only; send nothing and change nothing}';

    protected $description = 'Send stage deadline reminders and apply each stage\'s action once its deadline passes';

    public function handle(DpcStageService $stages, MessageTemplates $templates): int
    {
        $today = now()->startOfDay();
        $reminded = 0;
        $acted = 0;

        foreach ($stages->open() as $proposal) {
            $deadline = $stages->deadline($proposal);

            if ($deadline === null || $deadline['due_at'] === null) {
                continue;
            }

            $stage = $deadline['stage'];
            $timeline = WorkflowStageTimeline::for($stage);

            if ($deadline['overdue'] && $timeline->on_expiry !== WorkflowStageTimeline::NOTIFY && in_array($timeline->on_expiry, $stage->expiryActions(), true)) {
                if (! $this->option('dry-run') && $this->act($proposal, $stage, $timeline->on_expiry, $deadline['due_at'])) {
                    $acted++;
                }

                continue;
            }

            $kind = $deadline['overdue'] ? 'overdue' : (($deadline['days_left'] ?? PHP_INT_MAX) <= 1 ? 'reminder' : null);

            if ($kind === null || $this->option('dry-run') || ! $this->due($proposal, $stage, $kind, $deadline['entered_at'], $timeline, $today)) {
                continue;
            }

            $sent = $this->remind($proposal, $stage, $kind, $deadline['due_at'], $timeline, $stages, $templates);

            DpcStageReminder::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'stage' => $stage->value,
                'kind' => $kind,
                'sent_on' => $today->toDateString(),
                'recipients' => $sent,
            ]);
            $reminded++;
        }

        $this->info("Reminders sent: {$reminded}. Automatic actions: {$acted}.");

        return self::SUCCESS;
    }

    /**
     * Whether this reminder is due today: the one before the deadline once; after
     * it, every `reminder_every_days` days. Counted within this visit to the stage.
     */
    private function due(DpcProposal $proposal, DpcStage $stage, string $kind, Carbon $entered, WorkflowStageTimeline $timeline, Carbon $today): bool
    {
        $last = DpcStageReminder::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('stage', $stage->value)
            ->where('kind', $kind)
            ->whereDate('sent_on', '>=', $entered->toDateString())
            ->latest('sent_on')
            ->value('sent_on');

        if ($last === null) {
            return true;
        }

        if ($kind === 'reminder') {
            return false;
        }

        return Carbon::parse($last)->startOfDay()->diffInDays($today) >= max(1, $timeline->reminder_every_days);
    }

    private function remind(DpcProposal $proposal, DpcStage $stage, string $kind, Carbon $due, WorkflowStageTimeline $timeline, DpcStageService $stages, MessageTemplates $templates): int
    {
        $values = [
            'dpc' => (string) ($proposal->dpc_code ?? $proposal->citation()),
            'stage' => $stage->label(),
            'action' => $stage->action(),
            'due' => $due->format('d-m-Y'),
            'url' => route('dpc-proposals.show', $proposal),
        ];
        $mail = $timeline->remind_email ? $templates->email('stage.'.$kind, $values) : null;
        $sms = $timeline->remind_sms ? $templates->sms('stage.'.$kind, $values) : null;
        $sent = 0;

        foreach (User::query()->whereIn('id', $stages->actorIds($proposal, $stage))->get() as $user) {
            try {
                if ($mail !== null && $user->email) {
                    Mail::raw($mail['body'], fn ($message) => $message->to((string) $user->email)->subject($mail['subject']));
                    $sent++;
                }

                if ($sms !== null && $user->mobile) {
                    app(SmsSender::class)->send((string) $user->mobile, $sms['body'], $sms['dlt_template_id']);
                    $sent++;
                }
            } catch (Throwable $e) {
                Log::warning('Stage reminder could not be sent to one recipient.', ['proposal_id' => $proposal->id, 'stage' => $stage->value, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** The stage's action once its deadline has passed: cancel, or forward. */
    private function act(DpcProposal $proposal, DpcStage $stage, string $action, Carbon $due): bool
    {
        [$from, $to, $event] = match (true) {
            $action === WorkflowStageTimeline::CANCEL => [null, DpcProposalStatus::Cancelled, 'auto_cancel'],
            $stage === DpcStage::Ah => [DpcProposalStatus::Submitted, DpcProposalStatus::PendingSecretary, 'ah_auto_forward'],
            $stage === DpcStage::Secretary => [DpcProposalStatus::PendingSecretary, DpcProposalStatus::Approved, 'secretary_escalated'],
            default => [null, null, null],
        };

        if ($to === null) {
            return false;
        }

        $remarks = (string) __('dpc.stage.auto.'.$event, ['stage' => $stage->label(), 'date' => $due->format('d-m-Y')]);

        $done = DB::transaction(function () use ($proposal, $stage, $from, $to, $event, $remarks): ?array {
            /** @var DpcProposal|null $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            // Moved on since it was read: nothing to do.
            if ($locked === null || app(DpcStageService::class)->stageOf($locked) !== $stage || ($from !== null && $locked->status !== $from)) {
                return null;
            }

            $previous = $locked->status->value;
            $changes = ['status' => $to->value];

            if ($event === 'secretary_escalated') {
                $changes += ['secretary_escalated_at' => now(), 'forwarded_at' => now()];
            } elseif ($event === 'auto_cancel') {
                $changes += ['cancelled_at' => now()];
            }

            $locked->forceFill($changes)->save();

            if ($event === 'auto_cancel') {
                DpcReviewLink::query()->where('review_dpc_proposal_id', $locked->id)->whereNull('superseded_at')->delete();
                app(DpcRevisionService::class)->recordCancellation($locked);
            } else {
                app(DpcRevisionService::class)->recordApproval($locked);
            }

            WorkflowTransition::query()->create([
                'workflow' => 'dpc_proposal',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'event' => $event,
                'from_state' => $previous,
                'to_state' => $to->value,
                'actor_id' => null,
                'remarks' => $remarks,
                'meta' => ['automatic' => true, 'reason' => 'stage_deadline_passed', 'deadline_type' => 'workflow_stage', 'stage' => $stage->value],
                'occurred_at' => now(),
            ]);

            app(ActivityTimelineService::class)->record('dpc_proposal.'.$event, $locked, $remarks, ['automatic' => true, 'deadline_type' => 'workflow_stage', 'stage' => $stage->value]);

            return ['proposal' => $locked, 'to' => $to->value];
        });

        if ($done === null) {
            return false;
        }

        try {
            $roles = $event === 'ah_auto_forward' ? [Role::Secretary] : [Role::Chairman, Role::Secretary];
            $tell = User::role(array_map(fn (Role $role): string => $role->value, $roles))->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            WorkflowTransitioned::dispatch('dpc_proposal', $event, $done['to'], $done['proposal']->department_id, ['authorized_officer', 'administrative_head'], $remarks, $tell);
        } catch (Throwable $e) {
            Log::warning('Automatic action notification could not be sent; the action stands.', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
        }

        return true;
    }
}
