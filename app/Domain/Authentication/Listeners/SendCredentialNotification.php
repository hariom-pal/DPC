<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\CredentialsIssued;
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

    public function __construct(private readonly SmsSender $sms) {}

    public function handle(CredentialsIssued $event): void
    {
        $user = $event->user;
        $key = $event->reason === CredentialsIssued::REASON_RESET ? 'reset' : 'created';
        $loginUrl = route('login');
        $subject = (string) __("authui.credentials.{$key}.subject");
        $message = (string) __("authui.credentials.{$key}.message", ['login' => $user->email ?? $user->mobile, 'url' => $loginUrl]);

        try {
            if ($user->mobile !== null && Feature::SmsGateway->enabled()) {
                $this->sms->send($user->mobile, $message);
            }

            if ($user->email !== null) {
                Mail::raw($message, function ($mail) use ($user, $subject): void {
                    $mail->to($user->email)->subject($subject);
                });
            }
        } catch (Throwable $e) {
            Log::warning('Account notice could not be delivered.', ['user' => $user->id, 'error' => $e::class]);
        }
    }
}
