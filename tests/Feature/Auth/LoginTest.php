<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function verifiedUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::AuthorizedOfficer->value);

        return $user;
    }

    public function test_user_can_login_with_mobile(): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'login' => $user->mobile,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_with_email(): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'login' => $user->mobile,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();
        $user->assignRole(Role::AuthorizedOfficer->value);

        $this->post(route('login.store'), [
            'login' => $user->mobile,
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_unverified_account_is_redirected_to_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole(Role::AuthorizedOfficer->value);

        $this->post(route('login.store'), [
            'login' => $user->mobile,
            'password' => 'password',
        ])->assertRedirect(route('verify.show'));

        $this->assertGuest();
        $this->assertTrue(OtpVerification::query()->where('identifier', $user->mobile)->exists());
    }

    public function test_guest_cannot_reach_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
