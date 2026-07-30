<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\DepartmentStatus;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\VerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.verify', ['user' => $user]);
    }

    public function store(VerifyOtpRequest $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        /** @var array{user_id:int,purpose:string} $pending */
        $pending = $request->session()->get('pending_verification');
        $purpose = OtpPurpose::from($pending['purpose']);

        $verified = $this->verification->confirm(
            $user,
            $purpose,
            $request->input('email_code'),
            $request->input('mobile_code'),
        );

        if (! $verified) {
            throw ValidationException::withMessages([
                'mobile_code' => 'The verification codes are invalid or have expired. Please try again.',
            ]);
        }

        $request->session()->forget('pending_verification');
        $this->activateDepartmentIfRegistrant($user, $purpose);

        if ($purpose === OtpPurpose::Login) {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        return redirect()
            ->route('login')
            ->with('status', 'Verification complete. You may now log in.');
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('pending_verification');

        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return null;
        }

        return User::query()->find($pending['user_id']);
    }

    private function activateDepartmentIfRegistrant(User $user, OtpPurpose $purpose): void
    {
        if ($purpose !== OtpPurpose::Registration) {
            return;
        }

        $department = $user->department;

        if ($department !== null && $department->status !== DepartmentStatus::Active) {
            $department->forceFill(['status' => DepartmentStatus::Active->value])->save();
        }
    }
}
