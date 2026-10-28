<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\OtpVerification;
use App\Enums\Feature;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    /**
     * Generate and persist an OTP, then deliver it over its channel.
     *
     * Delivery is synchronous (2026-10-26): an e-mail the mail server refused is
     * reported to the person at once ({@see OtpDeliveryFailed}) and the code is
     * withdrawn, rather than queued and lost. The code also never sits in a queue
     * payload.
     *
     * @throws OtpThrottled when the last code for this identifier was issued less
     *                      than the resend cooldown ago, or too many were issued this hour
     * @throws OtpDeliveryFailed when the e-mail could not be sent
     */
    public function issue(string $identifier, OtpChannel $channel, OtpPurpose $purpose): void
    {
        $this->assertMaySend($identifier, $purpose);

        $code = $this->generateCode();

        $otp = OtpVerification::query()->create([
            'identifier' => $identifier,
            'channel' => $channel->value,
            'purpose' => $purpose->value,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds((int) config('dpc.otp.ttl_seconds')),
        ]);

        try {
            OtpRequested::dispatch($identifier, $channel, $purpose, $code);
        } catch (OtpDeliveryFailed $e) {
            $otp->delete();

            throw $e;
        }
    }

    /**
     * Seconds until a new code may be issued for this identifier and purpose; 0
     * when one may be issued now.
     */
    public function secondsUntilResend(string $identifier, OtpPurpose $purpose): int
    {
        $last = OtpVerification::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value)
            ->latest('id')
            ->value('created_at');

        if ($last === null) {
            return 0;
        }

        $cooldown = max(0, (int) config('dpc.otp.resend_cooldown_seconds', 60));
        $elapsed = (int) now()->diffInSeconds($last, true);

        return max(0, $cooldown - $elapsed);
    }

    /**
     * Whether an unexpired, unconsumed code for this identifier and purpose exists.
     */
    public function hasPendingCode(string $identifier, OtpPurpose $purpose): bool
    {
        return OtpVerification::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Verify a submitted OTP for an identifier + purpose, consuming it on success.
     */
    public function verify(string $identifier, OtpPurpose $purpose, string $code): bool
    {
        $otp = $this->matching($identifier, $purpose, $code);

        if ($otp === null) {
            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    /**
     * Verify several codes together: each attempt is counted, but nothing is
     * consumed unless every code is right (2026-10-27). A wrong mobile code no
     * longer burns the correct e-mail code entered beside it.
     *
     * @param  list<array{0: string, 1: string}>  $codes  [identifier, code] pairs
     */
    public function verifyAll(array $codes, OtpPurpose $purpose): bool
    {
        $matched = [];

        foreach ($codes as [$identifier, $code]) {
            $matched[] = $this->matching($identifier, $purpose, $code);
        }

        if (in_array(null, $matched, true)) {
            return false;
        }

        foreach ($matched as $otp) {
            $otp->forceFill(['consumed_at' => now()])->save();
        }

        return true;
    }

    /**
     * Withdraw every live code for these identifiers and purpose — a sign-in
     * challenge that ran out of attempts ends, and its codes with it (Phase 1).
     *
     * @param  list<string>  $identifiers
     */
    public function invalidate(array $identifiers, OtpPurpose $purpose): void
    {
        OtpVerification::query()
            ->whereIn('identifier', $identifiers)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    /**
     * The live code this submission matches, counting the attempt; null when it
     * does not match, has expired, was consumed or has run out of attempts.
     */
    private function matching(string $identifier, OtpPurpose $purpose, string $code): ?OtpVerification
    {
        $otp = OtpVerification::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($otp === null || $code === '') {
            return null;
        }

        if ($otp->attempts >= (int) config('dpc.otp.max_attempts')) {
            return null;
        }

        $otp->increment('attempts');

        return Hash::check($code, $otp->code_hash) ? $otp : null;
    }

    /**
     * Environments where a fixed code may be configured (business decisions,
     * 2026-10-26: local and testing; UAT added the same day). Never production.
     */
    private const FIXED_CODE_ENVIRONMENTS = ['local', 'testing', 'uat'];

    /**
     * Whether the fixed development/UAT code is in force on this host.
     *
     * **Both conditions, and the environment is checked here rather than in
     * configuration**: a configuration value can be set anywhere by accident,
     * whereas the environment names the host. An allowlist, not "anything but
     * production": a host on any other environment name — `production`, `staging`,
     * a typo — ignores a configured fixed code, and every code there is random.
     */
    public function fixedCodeInForce(): bool
    {
        return app()->environment(self::FIXED_CODE_ENVIRONMENTS)
            && (bool) config('dpc.otp.fixed.enabled', false)
            && (string) config('dpc.otp.fixed.code', '') !== '';
    }

    /**
     * Whether the fixed code also stands in for the mobile (SMS) OTP — the
     * separate `DPC_OTP_FIXED_MOBILE` switch (2026-10-26). While it holds, a mobile
     * number is proved with the fixed code instead of being waived.
     */
    public function fixedMobileCodeInForce(): bool
    {
        return $this->fixedCodeInForce() && (bool) config('dpc.otp.fixed.mobile', false);
    }

    /**
     * Whether a mobile number can be proved by OTP on this host: a real SMS
     * gateway, or the fixed mobile code. When neither holds, a mobile number is
     * covered by a recorded waiver ({@see MobileVerificationWaiver}).
     */
    public function mobileCodesUsable(): bool
    {
        return Feature::SmsGateway->enabled() || $this->fixedMobileCodeInForce();
    }

    private function assertMaySend(string $identifier, OtpPurpose $purpose): void
    {
        $wait = $this->secondsUntilResend($identifier, $purpose);

        if ($wait > 0) {
            throw new OtpThrottled($wait);
        }

        $maxPerHour = max(1, (int) config('dpc.otp.max_sends_per_hour', 5));
        $sentThisHour = OtpVerification::query()
            ->where('identifier', $identifier)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($sentThisHour >= $maxPerHour) {
            throw new OtpThrottled(3600, true);
        }
    }

    private function generateCode(): string
    {
        if ($this->fixedCodeInForce()) {
            return (string) config('dpc.otp.fixed.code');
        }

        $length = (int) config('dpc.otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
