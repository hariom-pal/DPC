<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Requests\VerifyOtpRequest;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Authentication\Services\SessionSignIn;
use App\Domain\Authentication\Services\SignInFlow;
use App\Domain\Authentication\Services\VerificationService;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly SessionSignIn $signIn,
        private readonly SecurityLog $log,
        private readonly SignInFlow $flow,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.verify', [
            'user' => $user,
            'needsEmail' => $this->verification->needsEmailCode($user),
            'needsMobile' => $this->verification->needsMobileCode($user),
        ]);
    }

    public function store(VerifyOtpRequest $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        /** @var array{user_id:int,purpose:string,failures?:int} $pending */
        $pending = $request->session()->get('pending_verification');
        $purpose = OtpPurpose::from($pending['purpose']);

        // Deactivated while the code was on its way: the challenge ends here.
        if (! $user->is_active) {
            $request->session()->forget('pending_verification');
            $this->log->record('login.refused_inactive', $user, $request);

            return redirect()->route('login')->withErrors(['login' => __('authui.otp.account_inactive')]);
        }

        $verified = $this->verification->confirm(
            $user,
            $purpose,
            $request->input('email_code'),
            $request->input('mobile_code'),
        );

        if (! $verified) {
            /*
             | Three wrong submissions per challenge in all (Phase 1, 2026-10-27).
             | Counted on the challenge, not on a code, so a resend does not reset
             | it; the third ends the challenge, withdraws its codes, and the
             | officer starts again from the password.
             */
            $failures = (int) ($pending['failures'] ?? 0) + 1;
            $this->log->record('otp.failed', $user, $request, ['attempt' => $failures]);

            if ($failures >= (int) config('dpc.otp.max_attempts')) {
                $this->verification->abandon($user, $purpose);
                $request->session()->forget('pending_verification');
                $this->log->record('otp.locked', $user, $request);

                return redirect()->route('login')->withErrors(['login' => __('authui.otp.too_many_attempts')]);
            }

            $request->session()->put('pending_verification', [...$pending, 'failures' => $failures]);

            throw ValidationException::withMessages([
                'mobile_code' => __('authui.otp.invalid'),
            ]);
        }

        $request->session()->forget('pending_verification');

        if ($purpose === OtpPurpose::Login) {
            $this->signIn->complete($request, $user, 'otp');

            return redirect()->intended(route('dashboard'));
        }

        return redirect()
            ->route('login')
            ->with('status', __('authui.otp.verified_login'));
    }

    /**
     * A fresh code on every channel still to be proved, honouring the cooldown and
     * the hourly limit.
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        /** @var array{user_id:int,purpose:string} $pending */
        $pending = $request->session()->get('pending_verification');

        try {
            $this->verification->resend($user, OtpPurpose::from($pending['purpose']));
        } catch (OtpThrottled|OtpDeliveryFailed $e) {
            return back()->withErrors(['mobile_code' => $e->getMessage()]);
        }

        return back()->with('status', __('authui.otp.resent'));
    }

    /**
     * The pending sign-in, only when it has reached the OTP step: identity and the
     * mobile declaration come first and cannot be skipped by coming here.
     */
    private function pendingUser(Request $request): ?User
    {
        $user = $this->flow->pendingUser($request);

        return $user !== null && $this->flow->next($user) === 'otp' ? $user : null;
    }
}
