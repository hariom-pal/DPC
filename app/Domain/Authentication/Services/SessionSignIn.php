<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\User;
use App\Http\Middleware\EnsureAccountReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Complete a sign-in once its challenge is passed (Phase 1, 2026-10-27).
 *
 * The session is marked with the account it passed MFA for. The account gate
 * ({@see EnsureAccountReady}) admits only a session carrying
 * that mark for the signed-in user, so no session can be authenticated without
 * it — not a "remember me" cookie (which no longer exists, and a surviving one
 * is refused), not a session restored any other way.
 */
class SessionSignIn
{
    public const MFA_SESSION_KEY = 'auth.mfa_user_id';

    public function __construct(private readonly SecurityLog $log) {}

    public function complete(Request $request, User $user, string $method): void
    {
        Auth::login($user);
        $request->session()->regenerate();

        // The session is this account's from now on: record its password hash so
        // `AuthenticateSession` never compares it with a previous account's (2026-10-26).
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        $request->session()->put(self::MFA_SESSION_KEY, $user->id);
        $request->session()->put(AccountSessions::EPOCH_SESSION_KEY, (int) $user->session_epoch);

        $this->log->record('login.succeeded', $user, $request, ['method' => $method]);
    }

    public static function passed(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::MFA_SESSION_KEY) === $user->id;
    }
}
