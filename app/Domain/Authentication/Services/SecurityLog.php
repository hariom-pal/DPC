<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\SecurityEvent;
use App\Domain\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write the security log (Phase 1, 2026-10-27).
 *
 * Events: `login.failed`, `login.refused_inactive`, `login.refused_no_channel`,
 * `login.challenge_issued`, `login.succeeded`, `otp.failed`, `otp.locked`,
 * `logout`, `session.ended_inactive`, `session.ended_mfa_missing`,
 * `account.deactivated`, `account.activated`; identity (2026-10-27): `identity.<outcome>`,
 * `identity.manually_verified`, `identity.corrected`, `identity.revoked`,
 * `sessions.revoked`; assignments: `assignment.switched`,
 * `assignment.effective`, `assignment.failed`.
 *
 * Never records a password, an OTP, or the identifier someone typed at sign-in —
 * an unknown login is logged with no user and no identifier. A failure to write
 * the log never fails the request it describes: it is reported to the
 * application log instead.
 */
class SecurityLog
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(string $event, ?User $user, ?Request $request = null, array $context = [], ?User $actor = null): void
    {
        $request ??= request();

        try {
            SecurityEvent::query()->create([
                'user_id' => $user?->id,
                'event' => $event,
                'actor_id' => $actor?->id,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
                'context' => $context === [] ? null : $context,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Security log could not be written.', ['event' => $event, 'error' => $e::class]);
        }
    }
}
