<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function __construct(private readonly SmsService $sms) {}

    /**
     * Generate, persist and dispatch an OTP to the given email/mobile.
     */
    public function issue(string $identifier, OtpChannel $channel, OtpPurpose $purpose): void
    {
        $code = $this->generateCode();

        OtpVerification::query()->create([
            'identifier' => $identifier,
            'channel' => $channel->value,
            'purpose' => $purpose->value,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds((int) config('dpc.otp.ttl_seconds')),
        ]);

        $this->dispatch($identifier, $channel, $code);
    }

    /**
     * Verify a submitted OTP for an identifier + purpose.
     */
    public function verify(string $identifier, OtpPurpose $purpose, string $code): bool
    {
        $otp = OtpVerification::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($otp === null) {
            return false;
        }

        $maxAttempts = (int) config('dpc.otp.max_attempts');

        if ($otp->attempts >= $maxAttempts) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    private function generateCode(): string
    {
        $fake = config('dpc.otp.fake_code');

        if ($fake !== null && $fake !== '') {
            return (string) $fake;
        }

        $length = (int) config('dpc.otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function dispatch(string $identifier, OtpChannel $channel, string $code): void
    {
        $ttlMinutes = (int) ceil(((int) config('dpc.otp.ttl_seconds')) / 60);
        $message = "Your ODPC verification code is {$code}. It is valid for {$ttlMinutes} minutes. Do not share it.";

        match ($channel) {
            OtpChannel::Email => Mail::raw($message, function ($mail) use ($identifier): void {
                $mail->to($identifier)->subject('ODPC Portal — Verification Code');
            }),
            OtpChannel::Sms => $this->sms->send($identifier, $message),
        };
    }
}
