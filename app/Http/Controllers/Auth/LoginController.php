<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Requests\LoginRequest;
use App\Domain\Authentication\Services\VerificationService;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

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
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => 'This account is deactivated. Please contact the CGPSC administrator.',
            ]);
        }

        if (! $user->isFullyVerified()) {
            $this->verification->start($user, OtpPurpose::Login);
            $request->session()->put('pending_verification', [
                'user_id' => $user->id,
                'purpose' => OtpPurpose::Login->value,
            ]);

            return redirect()
                ->route('verify.show')
                ->with('status', 'Your account is not verified yet. Enter the OTPs just sent to complete verification.');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
