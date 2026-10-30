<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The two things every domain service asks a seeder for: who is acting, and
 * the HTTP request the action arrived on.
 *
 * The demo data is built by calling the real services — the same
 * `DpcProposalService::approve()` the Administrative Head's button calls — so
 * that what is demonstrated is the application's own behaviour rather than a
 * hand-written imitation of it. Those services record an actor, and the
 * `Blameable` trait reads the authenticated user, so the seeder signs in as
 * each officer in turn exactly as that officer would have.
 */
final class DemoActor
{
    /**
     * Act as this user for the duration of the callback.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function as(User $user, callable $work): mixed
    {
        // Restored rather than merely cleared, so nesting one officer's action
        // inside another's — an Administrative Head approving inside a block
        // the Authorized Officer opened — leaves the outer actor in place.
        $previous = Auth::guard('web')->user();
        Auth::guard('web')->setUser($user);

        try {
            return $work();
        } finally {
            if ($previous instanceof User) {
                Auth::guard('web')->setUser($previous);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    /**
     * The request stub the workflow ledger records against.
     *
     * The IP and user agent land in `workflow_transitions`, which is evidence,
     * so they say plainly where the row came from rather than impersonating a
     * browser.
     */
    public static function request(): Request
    {
        // The declaration a Pre-DPC submission requires (MoM of 2026-10-29).
        return Request::create('/demo-seeder', 'POST', ['declaration' => '1'], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'e-DPC Demo Seeder',
        ]);
    }
}
