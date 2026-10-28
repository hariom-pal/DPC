<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * End every session of an account (2026-10-27): deactivation, identity correction,
 * forced re-verification, transfer, additional-charge removal.
 *
 * The account's `session_epoch` is raised; a session carries the epoch it signed
 * in under, and the account gate ends any session whose epoch is stale at its next
 * request, on any device and any session driver. The remember-me token is rotated
 * too, and with the database session driver the stored sessions are deleted
 * outright.
 */
class AccountSessions
{
    public const EPOCH_SESSION_KEY = 'auth.session_epoch';

    public function __construct(private readonly SecurityLog $log) {}

    public function invalidate(User $user, string $reason, ?User $actor = null): void
    {
        $user->forceFill(['session_epoch' => (int) $user->session_epoch + 1])->save();
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $this->log->record('sessions.revoked', $user, context: ['reason' => $reason], actor: $actor);
    }
}
