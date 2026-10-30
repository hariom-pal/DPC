<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Requests\CompleteInvitedRegistrationRequest;
use App\Domain\Authentication\Requests\StartInvitedRegistrationRequest;
use App\Domain\Authentication\Services\OtpService;
use App\Domain\Department\Exceptions\InvitationRefused;
use App\Domain\Department\Models\DepartmentInvitation;
use App\Domain\Department\Services\DepartmentInvitationService;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\DigiLockerProvider;
use App\Integrations\DigiLocker\DigiLockerIdentity;
use App\Integrations\DigiLocker\DigiLockerUnavailable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Department Admin registration by invitation (2026-10-26; DigiLocker-verified
 * 2026-10-27; department and e-mail only 2026-10-28).
 *
 * `/register/{token}` is the invitation. A new person: enters their mobile →
 * proves the invited e-mail and the mobile by OTP → authenticates with
 * DigiLocker, which supplies their name, date of birth and gender → chooses a
 * password. The e-mail is the department's own: when the department already has
 * an account, it passes to the newly invited person (their identity from
 * DigiLocker, the previous one kept on record); either way it becomes the
 * department's only Department Admin.
 *
 * Every step is held in the session under the invitation, and DigiLocker's
 * answer is accepted only for the state this session issued.
 */
class RegistrationController extends Controller
{
    private const SESSION_KEY = 'invited_registration';

    public const DIGILOCKER_KEY = 'register.digilocker';

    public function __construct(
        private readonly DepartmentInvitationService $invitations,
        private readonly OtpService $otp,
    ) {}

    public function create(): View
    {
        return view('auth.register');
    }

    public function show(Request $request, string $token): View
    {
        $invitation = $this->invitations->usable($token);

        if ($invitation === null) {
            return view('auth.register-invalid');
        }

        return view('auth.register-invited', [
            'invitation' => $invitation->load('department.district'),
            'token' => $token,
        ]);
    }

    /**
     * Step 1: the mobile; the OTPs are sent to it and to the invited e-mail.
     */
    public function start(StartInvitedRegistrationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);

        if (! $this->otp->mobileCodesUsable()) {
            return back()->withInput()->withErrors(['mobile' => __('invitations.errors.mobile_unverifiable')]);
        }

        $mobile = (string) $request->input('mobile');
        $request->session()->put($this->key($invitation), ['mobile' => $mobile, 'otp_verified' => false, 'identity' => null]);

        try {
            $this->sendCodes($invitation, $mobile);
        } catch (OtpThrottled|OtpDeliveryFailed $e) {
            return back()->withInput()->withErrors(['mobile' => $e->getMessage()]);
        }

        return redirect()->route('register.invitation.verify', ['token' => $token]);
    }

    public function verifyForm(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->invitations->usable($token);
        $state = $invitation === null ? null : $this->state($request, $invitation);

        if ($invitation === null || $state === null) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        return view('auth.register-verify', [
            'invitation' => $invitation,
            'token' => $token,
            'details' => $state,
            'needsMobile' => true,
        ]);
    }

    public function resend(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);
        $state = $this->state($request, $invitation);

        if ($state === null) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        try {
            $this->sendCodes($invitation, (string) $state['mobile']);
        } catch (OtpThrottled|OtpDeliveryFailed $e) {
            return back()->withErrors(['email_code' => $e->getMessage()]);
        }

        return back()->with('status', __('authui.otp.resent'));
    }

    /**
     * Step 2: both OTPs, checked together — a wrong mobile code consumes nothing.
     */
    public function verify(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);
        $state = $this->state($request, $invitation);

        if ($state === null) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        $digits = 'digits:'.(int) config('dpc.otp.length');
        $request->validate([
            'email_code' => ['required', $digits],
            'mobile_code' => ['required', $digits],
        ]);

        // Both, always (2026-10-28): there is no waiver.
        $codes = [[$invitation->email, (string) $request->input('email_code')], [(string) $state['mobile'], (string) $request->input('mobile_code')]];

        if (! $this->otp->verifyAll($codes, OtpPurpose::Registration)) {
            throw ValidationException::withMessages(['email_code' => __('authui.otp.invalid_pair')]);
        }

        $request->session()->put($this->key($invitation), ['otp_verified' => true] + $state);

        return redirect()->route('register.invitation.identity', ['token' => $token]);
    }

    /**
     * Step 3: DigiLocker, after both OTPs.
     */
    public function identityForm(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);

        if (! $this->mayVerifyIdentity($request, $invitation)) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        return view('auth.register-identity', ['invitation' => $invitation, 'token' => $token]);
    }

    public function identityStart(Request $request, string $token, DigiLockerProvider $digiLocker): RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);

        if (! $this->mayVerifyIdentity($request, $invitation)) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(self::DIGILOCKER_KEY, ['state' => $state, 'verifier' => $verifier, 'invitation' => $invitation->uuid, 'token' => $token]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($digiLocker->authorizationUrl($state, $challenge));
    }

    /**
     * DigiLocker's answer, reached through the one registered callback URL
     * (IdentityController::callback hands it here when the state is ours).
     */
    public function identityCallback(Request $request, DigiLockerProvider $digiLocker): RedirectResponse
    {
        $expected = $request->session()->pull(self::DIGILOCKER_KEY);

        if (! is_array($expected) || ! hash_equals((string) $expected['state'], (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['login' => __('identity.errors.state')]);
        }

        $token = (string) $expected['token'];
        $invitation = $this->invitations->usable($token);

        if ($invitation === null || $invitation->uuid !== $expected['invitation']) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        $back = redirect()->route('register.invitation.identity', ['token' => $token]);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->withErrors(['identity' => __('identity.errors.consent')]);
        }

        try {
            $result = $digiLocker->fetchIdentity((string) $request->query('code'), (string) $expected['verifier']);
        } catch (DigiLockerUnavailable) {
            return $back->withErrors(['identity' => __('identity.errors.technical')]);
        }

        if (! $result->aadhaarVerified) {
            return $back->withErrors(['identity' => __('identity.outcome.'.IdentityVerification::OUTCOME_NOT_AADHAAR_VERIFIED)]);
        }

        $state = $this->state($request, $invitation);
        $request->session()->put($this->key($invitation), [
            'identity' => $result->toArray() + ['aadhaar_verified' => true, 'reference' => $result->reference, 'method' => $digiLocker->name()],
        ] + (array) $state);

        return redirect()->route('register.invitation.password', ['token' => $token])->with('status', __('identity.verified'));
    }

    /**
     * Step 4: the password, with what DigiLocker returned shown read-only.
     */
    public function passwordForm(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);
        $state = $this->state($request, $invitation);

        if ($state === null || ! $state['otp_verified'] || ! is_array($state['identity'])) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        return view('auth.register-password', [
            'invitation' => $invitation, 'token' => $token, 'identity' => $state['identity'],
            'designations' => $this->designations($invitation)->get(['id', 'name']),
        ]);
    }

    public function complete(CompleteInvitedRegistrationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationOrFail($token);
        $state = $this->state($request, $invitation);

        if ($state === null || ! $state['otp_verified'] || ! is_array($state['identity'])) {
            return redirect()->route('register.invitation', ['token' => $token]);
        }

        $identity = $state['identity'];

        if (! $this->designations($invitation)->whereKey($request->integer('designation_id'))->exists()) {
            throw ValidationException::withMessages(['designation_id' => __('validation.in', ['attribute' => __('invitations.register.post')])]);
        }

        try {
            $this->invitations->register(
                $invitation,
                (string) $state['mobile'],
                new DigiLockerIdentity(
                    (string) $identity['name'],
                    Carbon::parse((string) $identity['date_of_birth'])->startOfDay(),
                    (string) $identity['gender'],
                    (bool) $identity['aadhaar_verified'],
                    isset($identity['reference']) ? (string) $identity['reference'] : null,
                ),
                (string) $identity['method'],
                (string) $request->input('password'),
                $request->integer('designation_id'),
            );
        } catch (InvitationRefused $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        $request->session()->forget($this->key($invitation));

        return redirect()->route('login')->with('status', __('invitations.registered'));
    }

    /**
     * The designations the Department Admin chooses from (2026-10-30): those the
     * department has mapped, or — for a department with no mapping yet, whose
     * Department Admin has still to map its posts — the whole active master.
     *
     * @return Builder<Designation>
     */
    private function designations(DepartmentInvitation $invitation): Builder
    {
        $mapped = Designation::query()->mappedIn((int) $invitation->department_id);

        return (clone $mapped)->exists()
            ? $mapped
            : Designation::query()->where('status', MasterStatus::Active->value)->orderBy('name');
    }

    private function mayVerifyIdentity(Request $request, DepartmentInvitation $invitation): bool
    {
        $state = $this->state($request, $invitation);

        return $state !== null && $state['otp_verified'] === true;
    }

    /**
     * @return array{mobile: string, otp_verified: bool, identity: array<string, mixed>|null}|null
     */
    private function state(Request $request, DepartmentInvitation $invitation): ?array
    {
        $state = $request->session()->get($this->key($invitation));

        if (! is_array($state) || ! isset($state['mobile'])) {
            return null;
        }

        return [
            'mobile' => (string) $state['mobile'],
            'otp_verified' => ($state['otp_verified'] ?? false) === true,
            'identity' => is_array($state['identity'] ?? null) ? $state['identity'] : null,
        ];
    }

    private function sendCodes(DepartmentInvitation $invitation, string $mobile): void
    {
        $this->otp->issue($invitation->email, OtpChannel::Email, OtpPurpose::Registration);
        $this->otp->issue($mobile, OtpChannel::Sms, OtpPurpose::Registration);
    }

    private function invitationOrFail(string $token): DepartmentInvitation
    {
        $invitation = $this->invitations->usable($token);
        abort_if($invitation === null, 404);

        return $invitation;
    }

    private function key(DepartmentInvitation $invitation): string
    {
        return self::SESSION_KEY.'.'.$invitation->uuid;
    }
}
