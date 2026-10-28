<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Services\SignInFlow;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\DigiLockerProvider;
use App\Integrations\DigiLocker\DigiLockerUnavailable;
use App\Integrations\Mock\SimulatedDigiLockerProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sign-in step 1: DigiLocker identity verification (2026-10-27).
 *
 * Runs on the pending sign-in (the password was right; nobody is signed in yet).
 * The redirect carries a one-time `state` bound to this pending sign-in, and a
 * PKCE verifier kept in the session; the callback refuses any other state.
 */
class IdentityController extends Controller
{
    private const STATE_KEY = 'identity.digilocker';

    public function __construct(
        private readonly SignInFlow $flow,
        private readonly IdentityVerificationService $identity,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->flow->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($this->flow->next($user) !== 'identity') {
            return $this->flow->continue($request, $user);
        }

        return view('auth.identity.show', [
            'user' => $user,
            'lockedUntil' => $this->identity->lockedUntil($user),
            'latest' => IdentityVerification::query()->where('user_id', $user->id)->latest('id')->first(),
        ]);
    }

    public function start(Request $request, DigiLockerProvider $digiLocker): RedirectResponse
    {
        $user = $this->flow->pendingUser($request);

        if ($user === null || $this->flow->next($user) !== 'identity') {
            return redirect()->route('login');
        }

        if ($this->identity->lockedUntil($user) !== null) {
            return redirect()->route('identity.show');
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(self::STATE_KEY, ['state' => $state, 'verifier' => $verifier, 'user_id' => $user->id]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($digiLocker->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, DigiLockerProvider $digiLocker): RedirectResponse
    {
        // An invitation's DigiLocker step shares the registered callback URL
        // (2026-10-27): the state says whose it is.
        $registration = $request->session()->get(RegistrationController::DIGILOCKER_KEY);
        if (is_array($registration) && hash_equals((string) $registration['state'], (string) $request->query('state'))) {
            return app(RegistrationController::class)->identityCallback($request, $digiLocker);
        }

        $user = $this->flow->pendingUser($request);
        $expected = $request->session()->pull(self::STATE_KEY);

        // A callback for another sign-in, a replay, or a forged state is refused.
        if ($user === null || ! is_array($expected) || $expected['user_id'] !== $user->id
            || ! hash_equals((string) $expected['state'], (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['login' => __('identity.errors.state')]);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            $this->identity->recordFailure($user, $digiLocker->name(), IdentityVerification::OUTCOME_CONSENT_DENIED, (string) $request->query('error', 'no code'));

            return redirect()->route('identity.show')->withErrors(['identity' => __('identity.errors.consent')]);
        }

        try {
            $result = $digiLocker->fetchIdentity((string) $request->query('code'), (string) $expected['verifier']);
        } catch (DigiLockerUnavailable $e) {
            $this->identity->recordFailure($user, $digiLocker->name(), IdentityVerification::OUTCOME_TECHNICAL_FAILURE, $e->getMessage());

            return redirect()->route('identity.show')->withErrors(['identity' => __('identity.errors.technical')]);
        }

        $row = $this->identity->recordResult($user, $digiLocker->name(), $result);

        if (! $row->grantsIdentity()) {
            return redirect()->route('identity.show')->withErrors(['identity' => __('identity.outcome.'.$row->outcome)]);
        }

        return $this->flow->continue($request, $user->fresh() ?? $user)->with('status', __('identity.verified'));
    }

    /**
     * The simulated DigiLocker screen (local and testing only).
     */
    public function simulator(Request $request, DigiLockerProvider $digiLocker): View
    {
        abort_unless($digiLocker instanceof SimulatedDigiLockerProvider && ! app()->environment('production'), 404);

        return view('auth.identity.simulator', ['state' => (string) $request->query('state')]);
    }

    public function simulate(Request $request, DigiLockerProvider $digiLocker): RedirectResponse
    {
        abort_unless($digiLocker instanceof SimulatedDigiLockerProvider && ! app()->environment('production'), 404);

        $state = (string) $request->input('state');

        if ($request->input('decision') === 'deny') {
            return redirect()->route('identity.callback', ['state' => $state, 'error' => 'access_denied']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'in:M,F'],
        ]);

        $code = $digiLocker->issueCode([
            'name' => $data['name'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'aadhaar_verified' => $request->boolean('aadhaar_verified'),
        ]);

        return redirect()->route('identity.callback', ['state' => $state, 'code' => $code]);
    }
}
