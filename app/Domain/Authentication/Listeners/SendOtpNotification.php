<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Listeners;

use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Enums\Feature;
use App\Enums\OtpChannel;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers an OTP over its channel, synchronously (2026-10-26).
 *
 * Synchronous so a mail server that refuses the message is reported to the person
 * waiting for the code ({@see OtpDeliveryFailed}), and so the code is never held in
 * a queue payload.
 *
 * **The code is never written to the application log.** While the SMS gateway is
 * unavailable an SMS OTP is not sent at all — the log records that it was not, and
 * not what it was. Mobile numbers are then covered by a recorded waiver, never by
 * pretending the code was delivered.
 */
class SendOtpNotification
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(OtpRequested $event): void
    {
        $ttlMinutes = (int) ceil(((int) config('dpc.otp.ttl_seconds')) / 60);
        $message = "Your ODPC verification code is {$event->code}. Valid for {$ttlMinutes} minutes. Do not share it.";

        if ($event->channel === OtpChannel::Sms) {
            if (Feature::SmsGateway->disabled()) {
                Log::info('SMS OTP not sent: the SMS gateway is not enabled.', ['purpose' => $event->purpose->value]);

                return;
            }

            $this->sms->send($event->identifier, $message);

            return;
        }

        try {
            Mail::raw($message, function ($mail) use ($event): void {
                $mail->to($event->identifier)->subject('ODPC Portal — Verification Code');
            });
        } catch (Throwable $e) {
            Log::error('OTP e-mail could not be sent.', ['purpose' => $event->purpose->value, 'error' => $e::class]);

            throw new OtpDeliveryFailed;
        }
    }
}
