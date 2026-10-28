<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OTP step an unverified account meets at login. (Registration verifies
 * both channels before the Register button since 2026-10-22, so a registered
 * officer no longer passes through it.)
 */
class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->unverified()->create();
        $this->user->assignRole(Role::AuthorizedOfficer->value);

        $this->post(route('login.store'), ['login' => $this->user->mobile, 'password' => 'password'])
            ->assertRedirect(route('verify.show'));
    }

    /**
     * The second factor is the mobile alone (2026-10-28): its code signs in and
     * proves the mobile; no e-mail code is asked.
     */
    public function test_the_mobile_code_signs_in_and_proves_the_mobile(): void
    {
        $this->get(route('verify.show'))->assertSee('name="mobile_code"', false)->assertDontSee('name="email_code"', false);

        $this->post(route('verify.store'), ['mobile_code' => '123456'])->assertRedirect(route('dashboard'));

        $this->assertNotNull($this->user->fresh()->mobile_verified_at);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_e_mail_code_alone_does_not_sign_in(): void
    {
        $this->post(route('verify.store'), ['email_code' => '123456'])->assertSessionHasErrors('mobile_code');
        $this->assertGuest();
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $this->post(route('verify.store'), ['mobile_code' => '000000'])->assertSessionHasErrors('mobile_code');

        $this->assertNull($this->user->fresh()->mobile_verified_at);
        $this->assertGuest();
    }

    /**
     * No way to deliver a mobile code — no SMS gateway and no fixed code — means
     * no second factor, so the sign-in is refused.
     */
    public function test_without_a_deliverable_mobile_the_sign_in_is_refused(): void
    {
        config(['dpc.otp.fixed.mobile' => false, 'features.sms_gateway' => false]);
        auth()->logout();
        $this->travel(2)->minutes();

        $this->post(route('login.store'), ['login' => $this->user->email, 'password' => 'password'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_resend_respects_the_cooldown(): void
    {
        $this->post(route('verify.resend'))->assertSessionHasErrors('mobile_code');

        $this->travel((int) config('dpc.otp.resend_cooldown_seconds') + 1)->seconds();
        $this->post(route('verify.resend'))->assertSessionHasNoErrors()->assertSessionHas('status', __('authui.otp.resent'));
    }
}
