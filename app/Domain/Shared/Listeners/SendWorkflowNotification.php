<?php

declare(strict_types=1);

namespace App\Domain\Shared\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifies a workflow transition over SMS + e-mail. Queued.
 *
 * Recipients come from two independent sources: roles scoped to the subject's
 * department, and specific user ids. The second was added for M6 — a CGPSC
 * Chairman or Member holds no department, so a role-plus-department query can
 * never reach them.
 *
 * Registration of this listener is gated by FEATURE_NOTIFICATIONS.
 */
class SendWorkflowNotification implements ShouldQueue
{
    /**
     * Dispatched only once the surrounding transaction commits. Without this,
     * a synchronous queue would run delivery *inside* the workflow's
     * transaction, and a failed SMS could roll back the transition itself.
     */
    public bool $afterCommit = true;

    public function __construct(private readonly SmsSender $sms) {}

    public function handle(WorkflowTransitioned $event): void
    {
        if (($event->departmentId === null || $event->notifyRoles === []) && $event->notifyUserIds === []) {
            return;
        }

        $message = "ODPC: {$event->workflow} was '{$event->event}' (status: {$event->toState}).";
        if ($event->remarks !== null && $event->remarks !== '') {
            $message .= " Remarks: {$event->remarks}";
        }

        // Two independent recipient sets: roles within the subject's
        // department, and specific users named outright. The second exists
        // because CGPSC Chairmen and Members hold no department, so no
        // role-plus-department query can ever reach them.
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($event): void {
                if ($event->departmentId !== null && $event->notifyRoles !== []) {
                    $query->orWhere(fn ($scoped) => $scoped
                        ->where('department_id', $event->departmentId)
                        ->role($event->notifyRoles));
                }

                if ($event->notifyUserIds !== []) {
                    $query->orWhereIn('id', $event->notifyUserIds);
                }
            })
            ->get();

        foreach ($recipients as $recipient) {
            // A workflow transition must never fail because a notification
            // did (architect directive). Delivery is best-effort per
            // recipient: one unreachable mobile must not stop the next
            // person being told, and none of it may surface as a workflow
            // error. Failures are logged so they remain visible.
            try {
                if ($recipient->mobile !== null) {
                    $this->sms->send($recipient->mobile, $message);
                }

                if ($recipient->email !== null) {
                    $email = $recipient->email;
                    Mail::raw($message, function ($mail) use ($email): void {
                        $mail->to($email)->subject('ODPC Portal — Workflow Update');
                    });
                }
            } catch (Throwable $e) {
                Log::warning('Workflow notification delivery failed.', [
                    'workflow' => $event->workflow,
                    'event' => $event->event,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
