<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\VerificationService;
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
        $field = str_contains($login, '@') ? 'email' : 'mobile';

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
