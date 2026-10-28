<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;

/**
 * The OTP challenge of every sign-in (Phase 1, 2026-10-27; mobile only 2026-10-28).
 *
 * Every sign-in is challenged with a code on the account's **mobile** — the second
 * factor. The e-mail is not asked at sign-in (it is proved once, at registration).
 * An account whose mobile cannot be reached — none recorded, or no way to deliver a
 * mobile code (a real SMS gateway, or the fixed development code) — cannot be
 * challenged, and is refused unless it is a Promotion Candidate's.
 */
class VerificationService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Whether this account can be challenged at all.
     */
    public function canChallenge(User $user): bool
    {
        return $this->channels($user) !== [];
    }

    /**
     * An account the portal cannot reach by any channel, allowed to sign in with
     * its password alone: a Promotion Candidate and nothing else — the account a
     * Seniority List creates for an officer to read their own DPC, which carries an
     * employee number and often no e-mail or mobile. Every such sign-in is logged.
     */
    public function mayProceedWithoutChallenge(User $user): bool
    {
        return $user->grantedRoleNames() === [Role::PromotionCandidate->value];
    }

    /**
     * Issue a code on every channel of the challenge. A channel whose last code is
     * younger than the resend cooldown keeps that code rather than failing —
     * signing in twice in a minute must not lock the officer out.
     *
     * @throws OtpThrottled when the hourly limit is reached
     * @throws OtpDeliveryFailed when the e-mail could not be sent
     */
    public function start(User $user, OtpPurpose $purpose): void
    {
        foreach ($this->channels($user) as [$identifier, $channel]) {
            if ($this->otp->hasPendingCode($identifier, $purpose) && $this->otp->secondsUntilResend($identifier, $purpose) > 0) {
                continue;
            }

            $this->otp->issue($identifier, $channel, $purpose);
        }
    }

    /**
     * A new code on every channel of the challenge, at the person's request.
     *
     * @throws OtpThrottled
     * @throws OtpDeliveryFailed
     */
    public function resend(User $user, OtpPurpose $purpose): void
    {
        foreach ($this->channels($user) as [$identifier, $channel]) {
            $this->otp->issue($identifier, $channel, $purpose);
        }
    }

    public function needsEmailCode(User $user): bool
    {
        // The second factor is the mobile alone (2026-10-28).
        return false;
    }

    public function needsMobileCode(User $user): bool
    {
        return $user->mobile !== null && $user->mobile !== '' && $this->otp->mobileCodesUsable();
    }

    /**
     * Check every code of the challenge together; nothing is consumed unless all
     * are right. A channel proved here is stamped verified.
     */
    public function confirm(User $user, OtpPurpose $purpose, ?string $emailCode, ?string $mobileCode): bool
    {
        $email = $this->needsEmailCode($user);
        $mobile = $this->needsMobileCode($user);

        $codes = [];

        if ($email) {
            $codes[] = [(string) $user->email, (string) $emailCode];
        }

        if ($mobile) {
            $codes[] = [(string) $user->mobile, (string) $mobileCode];
        }

        if ($codes === [] || ! $this->otp->verifyAll($codes, $purpose)) {
            return false;
        }

        $user->forceFill([
            'email_verified_at' => $email ? ($user->email_verified_at ?? now()) : $user->email_verified_at,
            'mobile_verified_at' => $mobile ? ($user->mobile_verified_at ?? now()) : $user->mobile_verified_at,
        ])->save();

        return true;
    }

    /**
     * End the challenge: every live code on its channels is withdrawn.
     */
    public function abandon(User $user, OtpPurpose $purpose): void
    {
        $this->otp->invalidate(array_map(fn (array $channel): string => $channel[0], $this->channels($user)), $purpose);
    }

    /**
     * @return list<array{0: string, 1: OtpChannel}>
     */
    private function channels(User $user): array
    {
        $channels = [];

        if ($this->needsEmailCode($user)) {
            $channels[] = [(string) $user->email, OtpChannel::Email];
        }

        if ($this->needsMobileCode($user)) {
            $channels[] = [(string) $user->mobile, OtpChannel::Sms];
        }

        return $channels;
    }
}
