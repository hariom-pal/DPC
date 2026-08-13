<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_is_throttled_after_configured_max_attempts(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::AuthorizedOfficer->value);

        $maxAttempts = (int) config('dpc.rate_limiting.login.max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->post(route('login.store'), [
                'login' => $user->mobile,
                'password' => 'not-the-password',
            ])->assertSessionHasErrors('login');
        }

        $this->post(route('login.store'), [
            'login' => $user->mobile,
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_rate_limit_is_keyed_per_identifier(): void
    {
        $userA = User::factory()->create();
        $userA->assignRole(Role::AuthorizedOfficer->value);
        $userB = User::factory()->create();
        $userB->assignRole(Role::AuthorizedOfficer->value);

        $maxAttempts = (int) config('dpc.rate_limiting.login.max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->post(route('login.store'), [
                'login' => $userA->mobile,
                'password' => 'not-the-password',
            ]);
        }

        // A different account is not affected by userA's lockout.
        $this->post(route('login.store'), [
            'login' => $userB->mobile,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($userB);
    }
}
