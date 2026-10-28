<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function loginIdentifiers(): array
    {
        return ['by mobile' => ['mobile'], 'by email' => ['email']];
    }

    #[DataProvider('loginIdentifiers')]
    public function test_a_user_can_login_with_either_identifier(string $identifier): void
    {
        $user = $this->verifiedUser();

        $this->post(route('login.store'), [
            'login' => $user->{$identifier},
            'password' => 'password',
        ])->assertRedirect(route('verify.show'));
        // Every sign-in is challenged (Phase 1, 2026-10-27).
        $this->passSignInChallenge()->assertRedirect(route('dashboard'));

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
        config(['dpc.otp.fixed.mobile' => true]); // a mobile the portal can reach
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
