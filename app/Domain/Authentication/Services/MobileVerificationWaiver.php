<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\User;

/**
 * The temporary mobile-verification exception (business decision, 2026-10-26).
 *
 * While the SMS gateway is unavailable a mobile number cannot be proved by OTP.
 * The exception is recorded explicitly — who granted it, when and why — and
 * `mobile_verified_at` stays null, so nobody can later mistake a waived number for
 * a verified one. Once the gateway is enabled — or the fixed mobile code is
 * switched on (`DPC_OTP_FIXED_MOBILE`, 2026-10-26) — no new waiver is granted and
 * the mobile OTP is required.
 */
class MobileVerificationWaiver
{
    public const REASON_SMS_UNAVAILABLE = 'SMS gateway unavailable';

    public function __construct(private readonly OtpService $otp) {}

    public function available(): bool
    {
        return ! $this->otp->mobileCodesUsable();
    }

    /**
     * Record the waiver on an account whose mobile is not verified, if the policy
     * allows one. Returns whether a waiver is in place afterwards.
     */
    public function grant(User $user, ?User $grantedBy, string $reason = self::REASON_SMS_UNAVAILABLE): bool
    {
        if ($user->mobile_verified_at !== null || $user->mobile === null) {
            return false;
        }

        if (! $this->available()) {
            return $user->hasMobileWaiver();
        }

        $user->forceFill([
            'mobile_verification_waived_at' => now(),
            'mobile_verification_waived_by' => $grantedBy?->id,
            'mobile_verification_waiver_reason' => $reason,
        ])->save();

        return true;
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'mobile_verification_waived_at' => null,
            'mobile_verification_waived_by' => null,
            'mobile_verification_waiver_reason' => null,
        ])->save();
    }
}
