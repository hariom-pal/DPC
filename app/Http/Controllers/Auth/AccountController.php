<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\ContactChangeService;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Support\OfficialEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * My Profile, for every signed-in user (2026-11-03): everything the portal holds
 * about the account, and a change of e-mail and mobile proved by OTP on both
 * ({@see ContactChangeService}). Name, date of birth and gender are the verified
 * identity and are not edited here.
 */
class AccountController extends Controller
{
    private const PENDING = 'account.contact_change';

    public function __construct(private readonly ContactChangeService $contacts) {}

    public function show(Request $request): View
    {
        $user = $this->user($request);
        $user->loadMissing(['designation', 'activeAssignments.department']);

        return view('account.profile', [
            'user' => $user,
            'layout' => $user->hasRole(Role::Admin->value) ? 'layouts.admin' : 'layouts.app',
            'pending' => $request->session()->get(self::PENDING),
            'mobileCodesUsable' => $this->contacts->mobileCodesUsable(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email'))), 'mobile' => trim((string) $request->input('mobile'))]);

        // Its own error bag: the password form on the same page has a current_password too.
        $data = $request->validateWithBag('contact', [
            // The account holder, not just an open session (2026-11-03).
            'current_password' => ['required', 'current_password'],
            'email' => [
                'required', 'email', 'max:255',
                // An officer's address is official; a Promotion Candidate's account
                // is the one the portal allows without one (as at sign-in).
                ...($this->candidateOnly($user) ? [] : [OfficialEmail::RULE]),
                // Case-insensitive: Officer@… and officer@… are one mailbox.
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (User::query()->whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $value)])->exists()) {
                        $fail(__('validation.unique', ['attribute' => __('ui.email')]));
                    }
                },
            ],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'mobile')->ignore($user->id)],
        ], [
            'email.regex' => __('profile.account.official_email'),
            'mobile.regex' => __('profile.account.mobile_format'),
            'current_password.current_password' => __('profile.account.wrong_password'),
        ]);

        try {
            $pending = $this->contacts->start($user, $data['email'], $data['mobile']);
        } catch (OtpThrottled $e) {
            return back()->withInput()->withErrors(['email' => __('profile.account.wait', ['seconds' => $e->retryAfterSeconds])], 'contact');
        } catch (OtpDeliveryFailed) {
            return back()->withInput()->withErrors(['email' => __('profile.account.not_delivered')], 'contact');
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()], 'contact');
        }

        $request->session()->put(self::PENDING, $pending);

        return redirect()->route('account.show')->with('status', __('profile.account.codes_sent'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $pending = $this->pending($request);
        $data = $request->validate([
            'email_code' => ['required', 'digits_between:4,8'],
            'mobile_code' => [$this->contacts->mobileCodesUsable() ? 'required' : 'nullable', 'digits_between:4,8'],
        ]);

        try {
            $changed = $this->contacts->confirm($user, $pending, (string) $data['email_code'], (string) ($data['mobile_code'] ?? ''));
        } catch (RuntimeException $e) {
            $request->session()->forget(self::PENDING);

            return redirect()->route('account.show')->withErrors(['email' => $e->getMessage()]);
        }

        if (! $changed) {
            return back()->withErrors(['email_code' => __('profile.account.wrong_codes')]);
        }

        $request->session()->forget(self::PENDING);

        return redirect()->route('account.show')->with('status', __('profile.account.changed'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        try {
            $this->contacts->resend($pending);
        } catch (OtpThrottled $e) {
            return back()->withErrors(['email_code' => __('profile.account.wait', ['seconds' => $e->retryAfterSeconds])]);
        } catch (OtpDeliveryFailed) {
            return back()->withErrors(['email_code' => __('profile.account.not_delivered')]);
        }

        return back()->with('status', __('profile.account.codes_sent'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING);

        if (is_array($pending)) {
            $this->contacts->abandon($pending);
        }

        return redirect()->route('account.show');
    }

    /**
     * @return array{email: string, mobile: string, email_changed: bool, mobile_changed: bool}
     */
    private function pending(Request $request): array
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! isset($pending['email'], $pending['mobile'])) {
            throw new HttpResponseException(redirect()->route('account.show')->withErrors(['email' => __('profile.account.start_again')]));
        }

        /** @var array{email: string, mobile: string, email_changed: bool, mobile_changed: bool} $pending */
        return $pending;
    }

    private function candidateOnly(User $user): bool
    {
        return $user->grantedRoleNames() === [Role::PromotionCandidate->value];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
