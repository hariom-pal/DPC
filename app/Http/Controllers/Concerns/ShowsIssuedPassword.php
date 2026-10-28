<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Authentication\Models\User;
use Illuminate\Http\Response;
use SensitiveParameter;

/**
 * Render a system-issued temporary password as the direct response to the request
 * that issued it (business decision, 2026-10-26): no redirect, no flash — so it is
 * never written to the session store — and `Cache-Control: no-store` so the browser
 * does not keep it either.
 */
trait ShowsIssuedPassword
{
    protected function showIssuedPassword(User $user, #[SensitiveParameter] string $password, string $reason, string $layout, string $back): Response
    {
        return response()
            ->view('account.issued-password', [
                'user' => $user,
                'password' => $password,
                'reason' => $reason,
                'layout' => $layout,
                'back' => $back,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
