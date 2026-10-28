<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\DepartmentSeatChanged;
use App\Domain\Notification\Services\NotificationCentre;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells an existing officer that department registration changed their seat —
 * by SMS, e-mail and in-app. An officer who lost a seat must hear it from the
 * portal, not discover it at their next login.
 *
 * Delivery never undoes the registration: a failure is logged.
 */
class SendSeatChangeNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly NotificationCentre $centre,
    ) {}

    public function handle(DepartmentSeatChanged $event): void
    {
        $user = $event->user;
        $key = $event->replaced ? 'replaced' : 'posted';
        $replace = ['role' => $event->role->label(), 'department' => $event->department->name];
        $subject = (string) __("notifications.registration.{$key}.subject", $replace);
        $message = (string) __("notifications.registration.{$key}.message", $replace);

        try {
            if ($user->mobile !== null) {
                $this->sms->send($user->mobile, $message);
            }

            if ($user->email !== null) {
                Mail::raw($message, function ($mail) use ($user, $subject): void {
                    $mail->to($user->email)->subject($subject);
                });
            }

            $this->centre->notify(null, [], [$user->id], [
                'workflow' => 'department_registration',
                'event' => $event->replaced ? 'seat_replaced' : 'seat_posted',
                'title' => $subject,
                'body' => $message,
                'subject_type' => $event->department->getMorphClass(),
                'subject_id' => $event->department->id,
            ]);
        } catch (Throwable $e) {
            Log::warning('Department seat change notification could not be sent.', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
