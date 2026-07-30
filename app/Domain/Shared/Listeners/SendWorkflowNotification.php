<?php

declare(strict_types=1);

namespace App\Domain\Shared\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies the configured recipient roles (scoped to the subject's department)
 * of a workflow transition, over SMS + email. Queued.
 */
class SendWorkflowNotification implements ShouldQueue
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(WorkflowTransitioned $event): void
    {
        if ($event->departmentId === null || $event->notifyRoles === []) {
            return;
        }

        $message = "ODPC: {$event->workflow} was '{$event->event}' (status: {$event->toState}).";
        if ($event->remarks !== null && $event->remarks !== '') {
            $message .= " Remarks: {$event->remarks}";
        }

        $recipients = User::query()
            ->where('department_id', $event->departmentId)
            ->where('is_active', true)
            ->role($event->notifyRoles)
            ->get();

        foreach ($recipients as $recipient) {
            if ($recipient->mobile !== null) {
                $this->sms->send($recipient->mobile, $message);
            }

            if ($recipient->email !== null) {
                $email = $recipient->email;
                Mail::raw($message, function ($mail) use ($email): void {
                    $mail->to($email)->subject('ODPC Portal — Workflow Update');
                });
            }
        }
    }
}
