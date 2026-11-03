<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\PasswordResetService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Forgot password (2026-11-03): e-mail or mobile, then the OTPs on both and a new
 * password ({@see PasswordResetService}). Every answer reads the same whether or
 * not an account matched.
 */
class ForgotPasswordController extends Controller
{
    private const PENDING = 'password_reset';

    public function __construct(private readonly PasswordResetService $resets) {}

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $login = trim((string) $request->validate(['login' => ['required', 'string', 'max:255']])['login']);
        $user = $this->resets->find($login);

        if ($user !== null) {
            try {
                $this->resets->start($user);
            } catch (OtpThrottled $e) {
                return back()->withInput()->withErrors(['login' => __('authui.forgot.wait', ['seconds' => $e->retryAfterSeconds])]);
            } catch (OtpDeliveryFailed) {
                return back()->withInput()->withErrors(['login' => __('authui.forgot.not_delivered')]);
            }
        }

        // The same answer either way: nothing here says whether an account exists.
        $request->session()->put(self::PENDING, ['user_id' => $user?->id, 'login' => $login, 'expires' => now()->addMinutes(15)->timestamp]);

        return redirect()->route('password.forgot.verify')->with('status', __('authui.forgot.codes_sent'));
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('password.forgot');
        }

        return view('auth.forgot-password-verify', [
            'login' => $pending['login'],
            'mobileCodesUsable' => $this->resets->mobileCodesUsable(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('password.forgot')->withErrors(['login' => __('authui.forgot.start_again')]);
        }

        $data = $request->validate([
            'email_code' => ['required', 'digits_between:4,8'],
            'mobile_code' => [$this->resets->mobileCodesUsable() ? 'required' : 'nullable', 'digits_between:4,8'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $pending['user_id'] === null ? null : User::query()->find($pending['user_id']);

        if ($user === null || ! $user->is_active
            || ! $this->resets->complete($user, (string) $data['email_code'], (string) ($data['mobile_code'] ?? ''), (string) $data['password'])) {
            return back()->withErrors(['email_code' => __('authui.forgot.wrong_codes')]);
        }

        $request->session()->forget(self::PENDING);

        return redirect()->route('login')->with('status', __('authui.forgot.done'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('password.forgot');
        }

        $user = $pending['user_id'] === null ? null : User::query()->find($pending['user_id']);

        if ($user !== null && $user->is_active) {
            try {
                $this->resets->resend($user);
            } catch (OtpThrottled $e) {
                return back()->withErrors(['email_code' => __('authui.forgot.wait', ['seconds' => $e->retryAfterSeconds])]);
            } catch (OtpDeliveryFailed) {
                return back()->withErrors(['email_code' => __('authui.forgot.not_delivered')]);
            }
        }

        return back()->with('status', __('authui.forgot.codes_sent'));
    }

    /**
     * @return array{user_id: int|null, login: string, expires: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || (int) ($pending['expires'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        /** @var array{user_id: int|null, login: string, expires: int} $pending */
        return $pending;
    }
}
