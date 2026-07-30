<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\User;

/**
 * Email + mobile OTP verification of a user account (registration policy Q1:
 * both channels must be verified before the account is usable).
 */
class VerificationService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Issue OTPs on every channel the user holds.
     */
    public function start(User $user, OtpPurpose $purpose): void
    {
        if ($user->email !== null) {
            $this->otp->issue($user->email, OtpChannel::Email, $purpose);
        }

        if ($user->mobile !== null) {
            $this->otp->issue($user->mobile, OtpChannel::Sms, $purpose);
        }
    }

    /**
     * Confirm the codes; marks both channels verified only if all supplied
     * codes are valid.
     */
    public function confirm(User $user, OtpPurpose $purpose, ?string $emailCode, ?string $mobileCode): bool
    {
        $emailOk = $user->email === null
            || ($emailCode !== null && $this->otp->verify($user->email, $purpose, $emailCode));

        $mobileOk = $user->mobile === null
            || ($mobileCode !== null && $this->otp->verify($user->mobile, $purpose, $mobileCode));

        if (! ($emailOk && $mobileOk)) {
            return false;
        }

        $user->forceFill([
            'email_verified_at' => $user->email !== null ? now() : $user->email_verified_at,
            'mobile_verified_at' => $user->mobile !== null ? now() : $user->mobile_verified_at,
        ])->save();

        return true;
    }
}
