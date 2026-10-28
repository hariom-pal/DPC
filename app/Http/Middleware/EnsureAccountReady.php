<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Authentication\Services\SessionSignIn;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Http\Controllers\Auth\ProfileController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one gate after `auth` (Phase 1, 2026-10-27), on every authenticated route.
 *
 * Asked on every request, in order, so a session that was fine when it began is
 * judged by the account as it is now:
 *
 * 1. **The account is active.** A deactivated officer's session ends at its next
 *    request — wherever it is, on whatever device.
 * 2. **This session passed the sign-in challenge.** Only a session completed by
 *    {@see SessionSignIn} carries the mark; one restored any other way (a
 *    "remember me" cookie from before 2026-10-27, a session predating the gate)
 *    is ended and sent back to sign in.
 * 3. **No later event ended the account's sessions** (`session_epoch`), and the
 *    account's identity verification and mobile declaration still stand.
 * 4. **The system-issued password has been changed.** Until it is, only the
 *    password-change screen and sign-out are reachable.
 *
 * Replaces `EnsurePasswordChanged`, which guarded the application screens but
 * not every authenticated route (`previous-dpcs` sat outside it).
 */
class EnsureAccountReady
{
    /**
     * Reachable while a password change is pending.
     *
     * @var list<string>
     */
    private const PASSWORD_CHANGE_ROUTES = ['password.change.show', 'password.change.update', 'logout'];

    public function __construct(
        private readonly SecurityLog $log,
        private readonly IdentityVerificationService $identity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->end($request, $user, 'session.ended_inactive', __('authui.otp.account_inactive'));
        }

        if (! SessionSignIn::passed($request, $user)) {
            return $this->end($request, $user, 'session.ended_mfa_missing', __('authui.otp.sign_in_again'));
        }

        // Every session of the account was ended after this one signed in
        // (deactivation, identity correction, re-verification, transfer).
        if ((int) $request->session()->get(AccountSessions::EPOCH_SESSION_KEY, -1) !== (int) $user->session_epoch) {
            return $this->end($request, $user, 'session.ended_revoked', __('authui.otp.sign_in_again'));
        }

        // Identity verification is completed during sign-in; an account that lost
        // it signs in again to complete it.
        if (! $this->identity->isSatisfied($user)) {
            return $this->end($request, $user, 'session.ended_identity', __('authui.otp.sign_in_again'));
        }

        if ($user->must_change_password && ! $request->routeIs(...self::PASSWORD_CHANGE_ROUTES)) {
            return redirect()->route('password.change.show');
        }

        // An AO or AH confirms their profile at every sign-in (2026-10-28) — after a
        // pending password change, never instead of it: the two screens would
        // otherwise send each other back and forth for ever.
        if (! $user->must_change_password
            && ProfileController::mustConfirm($request, $user)
            && ! $request->routeIs('profile.show', 'profile.confirm', 'logout')) {
            return redirect()->route('profile.show');
        }

        return $next($request);
    }

    private function end(Request $request, User $user, string $event, string $message): Response
    {
        $this->log->record($event, $user, $request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['login' => $message]);
    }
}
