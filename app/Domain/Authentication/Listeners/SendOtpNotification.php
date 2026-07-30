<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\OtpRequested;
use App\Enums\OtpChannel;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers an OTP over its channel. Queued so SMS/email dispatch never blocks
 * the request.
 */
class SendOtpNotification implements ShouldQueue
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(OtpRequested $event): void
    {
        $ttlMinutes = (int) ceil(((int) config('dpc.otp.ttl_seconds')) / 60);
        $message = "Your ODPC verification code is {$event->code}. Valid for {$ttlMinutes} minutes. Do not share it.";

        match ($event->channel) {
            OtpChannel::Email => Mail::raw($message, function ($mail) use ($event): void {
                $mail->to($event->identifier)->subject('ODPC Portal — Verification Code');
            }),
            OtpChannel::Sms => $this->sms->send($event->identifier, $message),
        };
    }
}
