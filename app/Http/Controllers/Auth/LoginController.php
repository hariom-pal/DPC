<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Requests\LoginRequest;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Authentication\Services\SignInFlow;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly SignInFlow $flow,
        private readonly SecurityLog $log,
    ) {}

    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();
        $login = (string) $credentials['login'];

        /*
         | Three identifiers, and the third is what makes change item 17 usable.
         |
         | An email is unambiguous. A ten-digit string is a mobile number. Anything
         | else is an employee number — which is the *only* identifier a Promotion
         | Candidate's account has, because a Seniority List carries no email
         | address and no mobile number. Without this a candidate account could be
         | created and never signed into.
         |
         | Ordered from most specific to least, and each column is unique, so no
         | two officers can resolve to one another.
         */
        $field = match (true) {
            str_contains($login, '@') => 'email',
            (bool) preg_match('/^\d{10}$/', $login) => 'mobile',
            default => 'employee_number',
        };

        $user = User::query()->where($field, $login)->first();

        if ($user === null || ! Hash::check((string) $credentials['password'], $user->password)) {
            // Never the identifier typed: an unknown login is logged with no user.
            $this->log->record('login.failed', $user, $request);

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            $this->log->record('login.refused_inactive', $user, $request);

            throw ValidationException::withMessages([
                'login' => 'This account is deactivated. Please contact the CGPSC administrator.',
            ]);
        }

        // Identity (DigiLocker), the mobile declaration, then the OTP challenge
        // (Phases 1–5, 2026-10-27): the password alone never signs anyone in.
        return $this->flow->begin($request, $user);
    }

    /**
     * Abandon a pending sign-in (any step before the session is signed in).
     */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(SignInFlow::PENDING_KEY);

        return redirect()->route('login');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->log->record('logout', $user, $request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
