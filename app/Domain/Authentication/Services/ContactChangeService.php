<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Employee\Services\EmployeeDirectory;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A signed-in user changes their own e-mail and mobile (2026-11-03): "change
 * profile detail like email and mobile number but same time both should be
 * verified with OTP".
 *
 * **Both are proved together.** A code goes to the e-mail and to the mobile the
 * account will hold afterwards: the new one where it changes, the current one where
 * it does not. Nothing changes until both codes are right, and `verifyAll()`
 * consumes neither unless both are. A wrong mobile code does not burn the right
 * e-mail code.
 *
 * **Where no mobile code can be sent** (no SMS gateway and no fixed development
 * code), the mobile is covered by the recorded waiver the portal already uses at
 * registration ({@see MobileVerificationWaiver}), and only the e-mail is proved.
 *
 * Logged by field name only. The addresses themselves are not written to the
 * security log.
 */
final class ContactChangeService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly MobileVerificationWaiver $waiver,
        private readonly SecurityLog $securityLog,
        private readonly ActivityTimelineService $timeline,
        private readonly EmployeeDirectory $employees,
    ) {}

    /**
     * Whether a mobile code can be sent on this host.
     */
    public function mobileCodesUsable(): bool
    {
        return $this->otp->mobileCodesUsable();
    }

    /**
     * Send the codes for a change to `$email` / `$mobile` (the account's own value
     * where one does not change). Returns the pending change to keep in the session.
     *
     * @return array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}
     *
     * @throws OtpThrottled
     * @throws OtpDeliveryFailed
     */
    public function start(User $user, string $email, string $mobile): array
    {
        $pending = [
            'email' => $email,
            'mobile' => $mobile,
            'email_changed' => mb_strtolower($email) !== mb_strtolower((string) $user->email),
            'mobile_changed' => $mobile !== (string) $user->mobile,
        ];

        if (! $pending['email_changed'] && ! $pending['mobile_changed']) {
            throw new RuntimeException((string) __('profile.account.nothing_changed'));
        }

        $this->send($pending, false);

        return $pending;
    }

    /**
     * A new pair of codes, at the user's request.
     *
     * @param  array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}  $pending
     *
     * @throws OtpThrottled
     * @throws OtpDeliveryFailed
     */
    public function resend(array $pending): void
    {
        $this->send($pending, true);
    }

    /**
     * Apply the change when every code is right. False when a code is wrong,
     * expired or out of attempts.
     *
     * @param  array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}  $pending
     */
    public function confirm(User $user, array $pending, string $emailCode, string $mobileCode): bool
    {
        $codes = [[$pending['email'], $emailCode]];
        $mobileProved = $this->mobileCodesUsable();

        if ($mobileProved) {
            $codes[] = [$pending['mobile'], $mobileCode];
        }

        if (! $this->otp->verifyAll($codes, OtpPurpose::ContactChange)) {
            return false;
        }

        // Taken by another account since the codes were sent (any case of the e-mail).
        $taken = User::query()->whereKeyNot($user->id)
            ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($pending['email'])])->orWhere('mobile', $pending['mobile']))
            ->exists();

        if ($taken) {
            throw new RuntimeException((string) __('profile.account.taken_meanwhile'));
        }

        try {
            DB::transaction(function () use ($user, $pending, $mobileProved): void {
                $user->forceFill([
                    'email' => $pending['email'],
                    'mobile' => $pending['mobile'],
                    'email_verified_at' => now(),
                    'mobile_verified_at' => $mobileProved ? now() : null,
                ])->save();

                if ($mobileProved) {
                    $this->waiver->clear($user);
                } else {
                    $this->waiver->grant($user, $user);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Taken by another account between the request and the codes.
            throw new RuntimeException((string) __('profile.account.taken_meanwhile'));
        }

        $fields = array_keys(array_filter(['email' => $pending['email_changed'], 'mobile' => $pending['mobile_changed']]));
        $this->securityLog->record('account.contact_changed', $user, context: ['fields' => implode(',', $fields), 'mobile_otp' => $mobileProved], actor: $user);
        $this->timeline->record('user.contact_changed', $user, 'Changed own '.implode(' and ', $fields).' after OTP verification', ['fields' => $fields], $user);

        if ($user->employee_number !== null && $user->employee_number !== '') {
            $this->employees->recordUser($user);
        }

        return true;
    }

    /**
     * @param  array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}  $pending
     */
    public function abandon(array $pending): void
    {
        $this->otp->invalidate([$pending['email'], $pending['mobile']], OtpPurpose::ContactChange);
    }

    /**
     * @param  array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}  $pending
     */
    private function send(array $pending, bool $resend): void
    {
        $channels = [[$pending['email'], OtpChannel::Email]];

        if ($this->mobileCodesUsable()) {
            $channels[] = [$pending['mobile'], OtpChannel::Sms];
        }

        foreach ($channels as [$identifier, $channel]) {
            // Opening the screen twice in a minute keeps the code already sent.
            if (! $resend && $this->otp->hasPendingCode($identifier, OtpPurpose::ContactChange)
                && $this->otp->secondsUntilResend($identifier, OtpPurpose::ContactChange) > 0) {
                continue;
            }

            $this->otp->issue($identifier, $channel, OtpPurpose::ContactChange);
        }
    }
}
