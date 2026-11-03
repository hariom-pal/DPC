<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Hash;

/**
 * Forgot password (2026-11-03): the account's owner proves its registered e-mail
 * and mobile by OTP, both at once, and sets a new password. Until then only the
 * CGPSC Administrator could issue one.
 *
 * **Nothing tells a stranger whether an account exists.** The screens say the same
 * thing for any e-mail or mobile. A code goes out only to a real, active account,
 * and the codes typed in for anything else simply fail.
 *
 * **Every channel the account can be reached on is proved**: the e-mail, and the
 * mobile wherever a mobile code can be sent. A wrong mobile code does not burn the
 * right e-mail code (`verifyAll`).
 *
 * The new password ends every other session of the account and is logged, without
 * the password, in the security log.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AccountSessions $sessions,
        private readonly SecurityLog $securityLog,
    ) {}

    /**
     * The active account this e-mail or mobile belongs to, or null.
     */
    public function find(string $login): ?User
    {
        $login = trim($login);
        $field = match (true) {
            str_contains($login, '@') => 'email',
            (bool) preg_match('/^\d{10}$/', $login) => 'mobile',
            default => null,
        };

        if ($field === null) {
            return null;
        }

        $user = User::query()
            ->when($field === 'email', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($login)]), fn ($q) => $q->where('mobile', $login))
            ->first();

        return $user !== null && $user->is_active && $this->channels($user) !== [] ? $user : null;
    }

    /**
     * Send the codes to the account's e-mail and mobile.
     *
     * @throws OtpThrottled
     * @throws OtpDeliveryFailed
     */
    public function start(User $user): void
    {
        foreach ($this->channels($user) as [$identifier, $channel]) {
            if ($this->otp->hasPendingCode($identifier, OtpPurpose::PasswordReset)
                && $this->otp->secondsUntilResend($identifier, OtpPurpose::PasswordReset) > 0) {
                continue;
            }

            $this->otp->issue($identifier, $channel, OtpPurpose::PasswordReset);
        }
    }

    /**
     * @throws OtpThrottled
     * @throws OtpDeliveryFailed
     */
    public function resend(User $user): void
    {
        foreach ($this->channels($user) as [$identifier, $channel]) {
            $this->otp->issue($identifier, $channel, OtpPurpose::PasswordReset);
        }
    }

    /**
     * Whether the screen asks for a mobile code (the same for every account, so the
     * screen never says which accounts exist).
     */
    public function mobileCodesUsable(): bool
    {
        return $this->otp->mobileCodesUsable();
    }

    /**
     * Set the new password when every code is right; false otherwise.
     */
    public function complete(User $user, string $emailCode, string $mobileCode, string $password): bool
    {
        $codes = array_map(
            fn (array $channel): array => [$channel[0], $channel[1] === OtpChannel::Email ? $emailCode : $mobileCode],
            $this->channels($user),
        );

        if ($codes === [] || ! $this->otp->verifyAll($codes, OtpPurpose::PasswordReset)) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Whoever may have known the old password is signed out everywhere.
        $this->sessions->invalidate($user, 'password_reset_by_owner', $user);
        $this->securityLog->record('password.reset_by_owner', $user, actor: $user);

        return true;
    }

    /**
     * @return list<array{0: string, 1: OtpChannel}>
     */
    private function channels(User $user): array
    {
        $channels = [];

        if ($user->email !== null && $user->email !== '') {
            $channels[] = [(string) $user->email, OtpChannel::Email];
        }

        if ($user->mobile !== null && $user->mobile !== '' && $this->otp->mobileCodesUsable()) {
            $channels[] = [(string) $user->mobile, OtpChannel::Sms];
        }

        return $channels;
    }
}
