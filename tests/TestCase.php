<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\SessionSignIn;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Master\Models\ClassMaster;
use App\Http\Controllers\Auth\ProfileController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Give every test its own disk, clear the Class Master's memo, and make sure
     * the Commission has a Chairman.
     *
     * **The disk had to move here, and for the same reason the memo is here.**
     * Ten test files faked `local` and six did not, so a document or signature
     * test wrote **real files into `storage/app/private`** — a hundred of them per
     * run of one test class. Two consequences, and the second is the serious one:
     * a developer's storage directory filled with orphans after every run, and a
     * test asserting that a document exists could be satisfied by a file the
     * *previous* run left behind. An evidence-integrity test that passes on
     * yesterday's evidence is not testing anything.
     *
     * `local` is the only disk the application writes to — documents, signatures,
     * import batches and Government Order PDFs all land there — so faking it once
     * isolates all of them. Laravel clears the faked directory itself.
     *
     * The same argument covers the memo below: a cache that has to be remembered
     * per test is a cache that will be forgotten, and so is a faked disk.
     */
    /**
     * Signing in as another user in a test is a fresh sign-in, as it is in a browser.
     *
     * `AuthenticateSession` (2026-10-26) keeps the signed-in account's password hash
     * in the session and logs the session out when it no longer matches. A real
     * login records the new account's hash; `actingAs()` does not go through login,
     * so without this a test that switches from one account to another would be
     * signed out for carrying the previous account's hash.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        parent::be($user, $guard);

        if ($this->app->bound('session.store')) {
            $this->app['session.store']->forget('password_hash_'.($guard ?? $this->app['auth']->getDefaultDriver()));

            // `actingAs` stands for a completed sign-in, the OTP challenge included
            // (Phase 1, 2026-10-27); tests of the challenge itself sign in over HTTP.
            $this->app['session.store']->put(SessionSignIn::MFA_SESSION_KEY, $user->getAuthIdentifier());
            $this->app['session.store']->put(AccountSessions::EPOCH_SESSION_KEY, (int) ($user->session_epoch ?? 0));
            // …and the AO's / AH's profile confirmed for the session (2026-10-28).
            $this->app['session.store']->put(ProfileController::SESSION_KEY, true);
        }

        return $this;
    }

    /**
     * Pass the sign-in OTP challenge with the fixed test code, after a password
     * step that redirected to it (Phase 1, 2026-10-27: every sign-in is challenged).
     */
    protected function passSignInChallenge(): TestResponse
    {
        return $this->post(route('verify.store'), ['email_code' => '123456', 'mobile_code' => '123456']);
    }

    /**
     * From the identity step of a pending sign-in, through the simulated DigiLocker
     * (with the officer's own recorded details unless `$as` overrides them) to the
     * OTP challenge (2026-10-27). Returns the last
     * response — a redirect to the OTP screen when the identity matched.
     *
     * @param  array<string, string>  $as
     */
    protected function passIdentityAndDeclaration(User $user, array $as = []): TestResponse
    {
        $start = $this->post(route('identity.start'));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $user = $user->fresh() ?? $user;

        $callback = $this->post(route('identity.simulator.submit'), array_replace([
            'state' => (string) ($query['state'] ?? ''),
            'decision' => 'allow',
            'name' => (string) $user->name,
            'date_of_birth' => (string) $user->date_of_birth?->toDateString(),
            'gender' => (string) $user->gender,
            'aadhaar_verified' => '1',
        ], $as));

        return $this->get((string) $callback->headers->get('Location'));
    }

    /**
     * Stand in for a completed first sign-in on an account created by a service or
     * seeder (2026-10-27): identity verified through the simulated DigiLocker.
     */
    protected function markIdentityVerified(User $user): User
    {
        $user->forceFill(['identity_verified_at' => now(), 'identity_verification_method' => 'digilocker_simulated'])->save();

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        ClassMaster::flushCache();

        $this->ensureCgpscChairman();
    }

    /**
     * Record a CGPSC Chairman, because item 9 makes one a precondition for
     * everything downstream.
     *
     * The change register of 2026-08-26 requires every DPC committee to include the
     * CGPSC Chairman, and `DpcCommitteeService` refuses to compose a committee when
     * no Chairman is on record. That turns "a Chairman exists" into a precondition
     * of the *whole* DPC workflow — not of the committee screen alone — so a fixture
     * that omits it fails at submission with a message about a master nobody was
     * testing.
     *
     * It lives here rather than in each fixture for the reason already written
     * above about the faked disk: a precondition that has to be remembered per test
     * is one that will be forgotten. A test that cares about the Chairman
     * specifically — appointment, succession, the missing-Chairman refusal — deletes
     * or replaces this row, which is cheap and explicit.
     *
     * **Guarded on the table existing.** Some tests run without `RefreshDatabase`,
     * and a few exercise migrations directly; touching a table that is not there
     * would turn those into errors about the Chairman.
     */
    private function ensureCgpscChairman(): void
    {
        if (! Schema::hasTable('cgpsc_chairmen')) {
            return;
        }

        if (CgpscChairman::query()->serving()->exists()) {
            return;
        }

        $chairman = new CgpscChairman([
            'name' => 'Dr. Ajay Kumar Shrivastava',
            'designation' => 'Chairman, Chhattisgarh Public Service Commission',
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        // `active_flag` is not fillable — appointment is the service's job, and the
        // service writes a timeline entry a fixture does not want. Forced here for
        // the same reason the fixtures force `PreDpcProposal`'s workflow columns.
        $chairman->forceFill(['active_flag' => 1])->save();
    }
}
