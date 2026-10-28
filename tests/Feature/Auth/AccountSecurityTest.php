<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\SecurityEvent;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SessionSignIn;
use App\Domain\Department\Models\Department;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\Role;
use App\Http\Middleware\EnsureAccountReady;
use App\Integrations\Contracts\AadhaarOtpProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0 and Phase 1 security (2026-10-27): every sign-in is challenged, no
 * remembered session, one account gate on every authenticated route, sessions end
 * on deactivation, OTP limits, and the security log.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->officer = User::factory()->create(['must_change_password' => false]);
        $this->officer->assignRole(Role::AuthorizedOfficer->value);
    }

    private function signInWithPassword(?User $user = null): void
    {
        $user ??= $this->officer;
        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('verify.show'));
    }

    // ------------------------------------------------- MFA on every sign-in

    public function test_a_verified_account_is_not_signed_in_by_its_password_alone(): void
    {
        $this->signInWithPassword();

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        // The second factor is the mobile (2026-10-28): no code goes to the e-mail.
        $this->assertTrue(OtpVerification::query()->where('identifier', $this->officer->mobile)->exists());
        $this->assertFalse(OtpVerification::query()->where('identifier', $this->officer->email)->exists());

        $this->passSignInChallenge()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->officer);
        // An AO or AH confirms their profile first (2026-10-28).
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $department = Department::query()->create(['code' => 'SEC', 'name' => 'Security Test Department', 'status' => 'registered']);
        $this->post(route('profile.confirm'), ['department_id' => $department->id, 'confirm' => '1'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_remember_me_is_gone_and_ignored(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('name="remember"', false);

        $before = $this->officer->getRememberToken();
        $this->post(route('login.store'), ['login' => $this->officer->email, 'password' => 'password', 'remember' => '1']);
        $response = $this->passSignInChallenge();

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_web_', $cookie->getName(), 'No remember-me cookie is issued.');
        }
        $this->assertSame($before, $this->officer->fresh()->getRememberToken(), 'No remember-me token is issued at sign-in.');
    }

    /**
     * A session the sign-in challenge did not complete — a remembered one, or one
     * restored any other way — is ended by the gate.
     */
    public function test_a_session_that_skipped_the_challenge_is_ended(): void
    {
        $this->actingAs($this->officer);
        session()->forget(SessionSignIn::MFA_SESSION_KEY);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $this->officer->id, 'event' => 'session.ended_mfa_missing']);
    }

    // ------------------------------------------------------------ OTP rules

    public function test_three_wrong_codes_end_the_challenge_even_across_a_resend(): void
    {
        $this->signInWithPassword();

        $this->post(route('verify.store'), ['mobile_code' => '000000'])->assertSessionHasErrors('mobile_code');
        $this->post(route('verify.store'), ['mobile_code' => '000001'])->assertSessionHasErrors('mobile_code');

        // A resend does not reset the count.
        $this->travel((int) config('dpc.otp.resend_cooldown_seconds') + 1)->seconds();
        $this->post(route('verify.resend'))->assertSessionHasNoErrors();

        $this->post(route('verify.store'), ['mobile_code' => '000002'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => __('authui.otp.too_many_attempts')]);

        // The challenge is over and its codes are withdrawn: the right code is useless now.
        $this->passSignInChallenge()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, OtpVerification::query()->where('identifier', $this->officer->mobile)->whereNull('consumed_at')->count());
        $this->assertSame(3, SecurityEvent::query()->where('event', 'otp.failed')->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $this->officer->id, 'event' => 'otp.locked']);
    }

    public function test_a_code_expires_after_five_minutes(): void
    {
        $this->assertSame(300, (int) config('dpc.otp.ttl_seconds'));
        $this->assertSame(3, (int) config('dpc.otp.max_attempts'));
        $this->assertSame(60, (int) config('dpc.otp.resend_cooldown_seconds'));

        $this->signInWithPassword();
        $this->travel(301)->seconds();

        $this->passSignInChallenge()->assertSessionHasErrors('mobile_code');
        $this->assertGuest();
    }

    public function test_an_account_deactivated_during_the_challenge_cannot_finish_it(): void
    {
        $this->signInWithPassword();
        $this->officer->forceFill(['is_active' => false])->save();

        $this->passSignInChallenge()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_account_with_no_reachable_channel_is_refused_but_a_candidate_is_not(): void
    {
        $unreachable = User::factory()->create(['email' => null, 'mobile' => null, 'employee_number' => 'EMP-9']);
        $unreachable->assignRole(Role::AuthorizedOfficer->value);

        $this->post(route('login.store'), ['login' => 'EMP-9', 'password' => 'password'])
            ->assertSessionHasErrors(['login' => __('authui.otp.no_channel')]);
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $unreachable->id, 'event' => 'login.refused_no_channel']);

        $candidate = User::factory()->create(['email' => null, 'mobile' => null, 'employee_number' => 'EMP-10', 'must_change_password' => false]);
        $candidate->assignRole(Role::PromotionCandidate->value);

        // Off by default: the candidate is refused like anyone else …
        $this->post(route('login.store'), ['login' => 'EMP-10', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();

        // … and signs in with the password alone only while the owner switches it on.
        config(['dpc.auth.candidate_password_only' => true]);

        $this->post(route('login.store'), ['login' => 'EMP-10', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($candidate);
        $this->assertDatabaseHas('security_events', ['user_id' => $candidate->id, 'event' => 'login.succeeded']);
    }

    // -------------------------------------------------------- deactivation

    public function test_deactivation_ends_the_accounts_sessions_and_remember_token(): void
    {
        $this->officer->setRememberToken('old-remember-token');
        $this->officer->save();
        $this->actingAs($this->officer)->get(route('dashboard'))->assertOk();

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        app(UserProvisioningService::class)->setActive($this->officer, false, $admin);

        $this->assertNotSame('old-remember-token', $this->officer->fresh()->getRememberToken());

        // The officer's session, still open, ends at its next request.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $this->officer->id, 'event' => 'account.deactivated', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('security_events', ['user_id' => $this->officer->id, 'event' => 'session.ended_inactive']);
    }

    // -------------------------------------------------- the account gate

    public function test_previous_dpcs_waits_for_the_password_change(): void
    {
        $staff = User::factory()->create(['must_change_password' => true]);
        $staff->assignRole(Role::CgpscStaff->value);

        $this->actingAs($staff)->get(route('previous-dpcs.index'))->assertRedirect(route('password.change.show'));
    }

    /**
     * Every authenticated GET route without parameters is behind the gate: a
     * pending password change reaches only the change screen, and a session that
     * skipped the challenge reaches nothing.
     */
    public function test_no_authenticated_route_bypasses_the_gate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route): bool => in_array('GET', $route->methods(), true)
                && $route->parameterNames() === []
                && in_array('auth', $route->gatherMiddleware(), true));

        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(EnsureAccountReady::class, $route->gatherMiddleware(), "{$route->uri()} is not behind the account gate.");
        }

        $pending = User::factory()->create(['must_change_password' => true]);
        $pending->assignRole(Role::AuthorizedOfficer->value);

        foreach ($routes as $route) {
            $name = (string) $route->getName();
            if ($name === 'password.change.show') {
                continue;
            }
            $this->actingAs($pending)->get('/'.ltrim($route->uri(), '/'))->assertRedirect(route('password.change.show'));
        }

        $this->actingAs($this->officer);
        session()->forget(SessionSignIn::MFA_SESSION_KEY);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_pending_challenge_reaches_no_authenticated_screen(): void
    {
        $this->signInWithPassword();

        foreach (['dashboard', 'pre-dpc.index', 'dpc-proposals.index', 'password.change.show'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
        $this->assertGuest();
    }

    // ------------------------------------------------------- security log

    public function test_sign_in_and_sign_out_are_logged_without_the_identifier(): void
    {
        $this->post(route('login.store'), ['login' => 'nobody@cg.gov.in', 'password' => 'x'])->assertSessionHasErrors('login');
        $this->post(route('login.store'), ['login' => $this->officer->email, 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->signInWithPassword();
        $this->passSignInChallenge();
        $this->post(route('logout'));

        $this->assertSame(
            ['login.failed', 'login.failed', 'login.challenge_issued', 'login.succeeded', 'logout'],
            SecurityEvent::query()->orderBy('id')->pluck('event')->all(),
        );
        $this->assertNull(SecurityEvent::query()->orderBy('id')->first()->user_id, 'An unknown login names no user.');
        $this->assertSame(0, SecurityEvent::query()->where('context', 'like', '%nobody@cg.gov.in%')->count());

        $this->expectException(\LogicException::class);
        SecurityEvent::query()->firstOrFail()->delete();
    }

    // ------------------------------------------------------ mock providers

    public function test_the_mock_aadhaar_provider_is_refused_in_production(): void
    {
        // Outside production, with UIDAI off, the mock answers.
        $this->assertSame('mock', app(AadhaarOtpProvider::class)->name());

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        app(AadhaarOtpProvider::class);
    }
}
