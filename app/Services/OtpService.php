<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Events\OtpRequested;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    /**
     * Generate and persist an OTP, then dispatch delivery via a queued
     * listener (SMS/email).
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

        OtpRequested::dispatch($identifier, $channel, $purpose, $code);
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
}
