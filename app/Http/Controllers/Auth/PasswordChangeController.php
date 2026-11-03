<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Requests\ChangePasswordRequest;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class PasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('auth.change-password');
    }

    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make((string) $request->validated('password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // From My Profile, back to it (2026-11-03).
        return redirect()
            ->route($request->input('return_to') === 'account' ? 'account.show' : 'dashboard')
            ->with('status', 'Your password has been updated.');
    }
}
