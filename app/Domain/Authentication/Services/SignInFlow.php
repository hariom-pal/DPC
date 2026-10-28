<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Enums\OtpPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The steps between a correct password and a signed-in session (2026-10-27):
 *
 * 1. identity — DigiLocker (or a recorded manual verification), first sign-in and
 *    after any withdrawal;
 * 2. otp — the mobile OTP challenge, at every sign-in.
 *
 * Then the session is signed in, and the account gate takes over (pending password
 * change, then the dashboard). Every step reads the pending sign-in, never trusts
 * it: each controller asks {@see next()} which step the account is on now, so no
 * step can be skipped by going to a later URL.
 */
class SignInFlow
{
    public const PENDING_KEY = 'pending_verification';

    public function __construct(
        private readonly IdentityVerificationService $identity,
        private readonly VerificationService $verification,
        private readonly SessionSignIn $signIn,
        private readonly SecurityLog $log,
    ) {}

    /**
     * `identity` or `otp`.
     */
    public function next(User $user): string
    {
        if (! $this->identity->isSatisfied($user)) {
            return 'identity';
        }

        return 'otp';
    }

    public function begin(Request $request, User $user): RedirectResponse
    {
        $request->session()->put(self::PENDING_KEY, [
            'user_id' => $user->id,
            'purpose' => OtpPurpose::Login->value,
            'failures' => 0,
        ]);

        return $this->continue($request, $user);
    }

    /**
     * Go to the step the account is on.
     */
    public function continue(Request $request, User $user): RedirectResponse
    {
        return match ($this->next($user)) {
            'identity' => redirect()->route('identity.show'),
            default => $this->challenge($request, $user),
        };
    }

    public function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return null;
        }

        $user = User::query()->find($pending['user_id']);

        return $user instanceof User && $user->is_active ? $user : null;
    }

    private function challenge(Request $request, User $user): RedirectResponse
    {
        /*
         | An account the portal cannot reach by any channel cannot be challenged.
         | It is refused — except, while `dpc.auth.candidate_password_only` is
         | switched on (it is off by default and must be approved to be switched on),
         | a Promotion Candidate's read-only account.
         */
        if (! $this->verification->canChallenge($user)) {
            if ((bool) config('dpc.auth.candidate_password_only', false) && $this->verification->mayProceedWithoutChallenge($user)) {
                $request->session()->forget(self::PENDING_KEY);
                $this->signIn->complete($request, $user, 'no_channel_candidate');

                return redirect()->intended(route('dashboard'));
            }

            $request->session()->forget(self::PENDING_KEY);
            $this->log->record('login.refused_no_channel', $user, $request);

            throw ValidationException::withMessages(['login' => __('authui.otp.no_channel')])->redirectTo(route('login'));
        }

        try {
            $this->verification->start($user, OtpPurpose::Login);
        } catch (OtpThrottled|OtpDeliveryFailed $e) {
            throw ValidationException::withMessages(['login' => $e->getMessage()])->redirectTo(route('login'));
        }

        $this->log->record('login.challenge_issued', $user, $request);

        return redirect()->route('verify.show')->with('status', __('authui.otp.challenge_sent'));
    }
}
