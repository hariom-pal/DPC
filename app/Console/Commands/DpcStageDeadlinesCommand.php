<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcStageReminder;
use App\Domain\Dpc\Services\DpcRevisionService;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * MoM of 16 November 2026, points 6 and 7, run once a day.
 *
 * - A stage whose deadline is a day or less away, or has passed, is reminded by
 *   e-mail to whoever is to act — once a day at most, and recorded.
 * - At the Secretary, CGPSC stage only, a DPC whose deadline has passed goes on to
 *   the Chairman, CGPSC automatically: the escalation is written to the workflow
 *   ledger and the timeline with no actor (it is the system's), and the Chairman,
 *   the Secretary and the department are told.
 */
class DpcStageDeadlinesCommand extends Command
{
    protected $signature = 'dpc:stage-deadlines {--dry-run : Report only; send nothing and escalate nothing}';

    protected $description = 'E-mail stage deadline reminders and escalate an overdue Secretary stage to the Chairman';

    public function handle(DpcStageService $stages, MessageTemplates $templates): int
    {
        $today = now()->toDateString();
        $reminded = 0;
        $escalated = 0;

        foreach ($stages->open() as $proposal) {
            $deadline = $stages->deadline($proposal);

            if ($deadline === null || $deadline['due_at'] === null) {
                continue;
            }

            if ($deadline['stage'] === DpcStage::Secretary && $deadline['overdue']) {
                if (! $this->option('dry-run') && $this->escalate($proposal, $deadline['due_at'])) {
                    $escalated++;
                }

                continue;
            }

            $kind = $deadline['overdue'] ? 'overdue' : (($deadline['days_left'] ?? PHP_INT_MAX) <= 1 ? 'reminder' : null);

            if ($kind === null) {
                continue;
            }

            $already = DpcStageReminder::query()
                ->where('dpc_proposal_id', $proposal->id)
                ->where('stage', $deadline['stage']->value)
                ->where('kind', $kind)
                ->whereDate('sent_on', $today)
                ->exists();

            if ($already || $this->option('dry-run')) {
                continue;
            }

            $sent = $this->remind($proposal, $deadline['stage'], $kind, $deadline['due_at'], $stages, $templates);

            DpcStageReminder::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'stage' => $deadline['stage']->value,
                'kind' => $kind,
                'sent_on' => $today,
                'recipients' => $sent,
            ]);
            $reminded++;
        }

        $this->info("Reminders sent: {$reminded}. Escalated to the Chairman: {$escalated}.");

        return self::SUCCESS;
    }

    private function remind(DpcProposal $proposal, DpcStage $stage, string $kind, Carbon $due, DpcStageService $stages, MessageTemplates $templates): int
    {
        $mail = $templates->email('stage.'.$kind, [
            'dpc' => (string) ($proposal->dpc_code ?? $proposal->citation()),
            'stage' => $stage->label(),
            'action' => $stage->action(),
            'due' => $due->format('d-m-Y'),
            'url' => route('dpc-proposals.show', $proposal),
        ]);

        if ($mail === null) {
            return 0;
        }

        $sent = 0;

        foreach (User::query()->whereIn('id', $stages->actorIds($proposal, $stage))->whereNotNull('email')->get() as $user) {
            try {
                Mail::raw($mail['body'], fn ($message) => $message->to((string) $user->email)->subject($mail['subject']));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Stage reminder could not be sent to one recipient.', ['proposal_id' => $proposal->id, 'stage' => $stage->value, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** The Secretary's deadline has passed: on to the Chairman, CGPSC (point 7). */
    private function escalate(DpcProposal $proposal, Carbon $due): bool
    {
        $remarks = sprintf('Escalated automatically: the Secretary, CGPSC did not act by the deadline of %s.', $due->format('d-m-Y'));

        $escalated = DB::transaction(function () use ($proposal, $remarks): ?DpcProposal {
            /** @var DpcProposal|null $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== DpcProposalStatus::PendingSecretary) {
                return null;
            }

            $locked->forceFill([
                'status' => DpcProposalStatus::Approved->value,
                'secretary_escalated_at' => now(),
                'forwarded_at' => now(),
            ])->save();

            app(DpcRevisionService::class)->recordApproval($locked);

            WorkflowTransition::query()->create([
                'workflow' => 'dpc_proposal',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'event' => 'secretary_escalated',
                'from_state' => DpcProposalStatus::PendingSecretary->value,
                'to_state' => DpcProposalStatus::Approved->value,
                'actor_id' => null,
                'remarks' => $remarks,
                'meta' => ['automatic' => true, 'reason' => 'secretary_deadline_passed'],
                'occurred_at' => now(),
            ]);

            app(ActivityTimelineService::class)->record('dpc_proposal.secretary_escalated', $locked, $remarks, ['automatic' => true]);

            return $locked;
        });

        if ($escalated === null) {
            return false;
        }

        try {
            $tell = User::role([Role::Chairman->value, Role::Secretary->value])->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            WorkflowTransitioned::dispatch('dpc_proposal', 'secretary_escalated', DpcProposalStatus::Approved->value, $escalated->department_id, ['authorized_officer', 'administrative_head'], $remarks, $tell);
        } catch (Throwable $e) {
            Log::warning('Escalation notification could not be sent; the escalation stands.', ['proposal_id' => $escalated->id, 'error' => $e->getMessage()]);
        }

        return true;
    }
}
