<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Enums\Feature;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells an officer that an account was created for them, or that its password was
 * reset — by e-mail, and by SMS once the gateway is available.
 *
 * **Never the password** (business decision, 2026-10-26): it is handed over by the
 * administrator who issued it, and the officer must change it at first login. A
 * failure to deliver is logged and never undoes the account.
 */
class SendCredentialNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly MessageTemplates $templates,
    ) {}

    public function handle(CredentialsIssued $event): void
    {
        $user = $event->user;
        $key = $event->reason === CredentialsIssued::REASON_RESET ? 'reset' : 'created';
        $loginUrl = route('login');
        // The Administrator's wording (2026-11-12); a channel switched off is not sent.
        $values = ['login' => $user->email ?? $user->mobile, 'url' => $loginUrl];
        $sms = $this->templates->sms("account.{$key}", $values);
        $email = $this->templates->email("account.{$key}", $values);

        try {
            if ($sms !== null && $user->mobile !== null && Feature::SmsGateway->enabled()) {
                $this->sms->send($user->mobile, $sms['body'], $sms['dlt_template_id']);
            }

            if ($email !== null && $user->email !== null) {
                Mail::raw($email['body'], function ($mail) use ($user, $email): void {
                    $mail->to($user->email)->subject($email['subject']);
                });
            }
        } catch (Throwable $e) {
            Log::warning('Account notice could not be delivered.', ['user' => $user->id, 'error' => $e::class]);
        }
    }
}
