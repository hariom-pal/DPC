<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers system-issued login credentials over SMS + email. Queued.
 */
class SendCredentialNotification implements ShouldQueue
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(CredentialsIssued $event): void
    {
        $user = $event->user;
        $loginId = $user->mobile ?? $user->email;
        $message = "ODPC Portal account created. Login ID: {$loginId}. Temporary password: {$event->temporaryPassword}. "
            .'You must change your password on first login after OTP verification.';

        if ($user->mobile !== null) {
            $this->sms->send($user->mobile, $message);
        }

        if ($user->email !== null) {
            Mail::raw($message, function ($mail) use ($user): void {
                $mail->to($user->email)->subject('ODPC Portal — Account Credentials');
            });
        }
    }
}
