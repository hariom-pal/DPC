<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Models\OtpVerification;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
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

    /**
     * The code to issue.
     *
     * Three sources, in a deliberate order:
     *
     * 1. **`APP_ENV=local` → the demonstration OTP** (`DEMO_OTP`, default
     *    `123456`). A demonstration machine has no SMS gateway and no mail
     *    transport, so an OTP reaches only the application log — and only if a
     *    queue worker happens to be running, because delivery is queued. That
     *    makes registration undemonstrable for reasons that have nothing to do
     *    with the workflow being shown.
     * 2. **`DPC_OTP_FAKE_CODE`** — the pre-existing knob, kept unchanged for
     *    deterministic assertions in tests and throwaway environments.
     * 3. **A random code**, which is what every other environment gets.
     *
     * **Only the randomness is removed, and only in `local`.** The row is still
     * written, the TTL still expires it, `max_attempts` still counts, and
     * {@see verify()} still consumes it — so the flow being demonstrated is the
     * real one rather than a bypass of it. There is no branch in `verify()` and
     * there must not be: a second acceptance path is a second thing to get wrong
     * on a host where it must never fire.
     *
     * **The gate is `app()->environment('local')`, not a config flag.** A
     * configuration value can be set anywhere by accident; the environment
     * cannot be `local` on a UAT or production host. `DEMO_OTP` sitting in a
     * production `.env` therefore does nothing whatsoever, which is the property
     * that makes this safe to ship.
     */
    private function generateCode(): string
    {
        if (app()->environment('local')) {
            $demo = (string) config('dpc.otp.demo_code', '');

            if ($demo !== '') {
                return $demo;
            }
        }

        $fake = config('dpc.otp.fake_code');

        if ($fake !== null && $fake !== '') {
            return (string) $fake;
        }

        $length = (int) config('dpc.otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
