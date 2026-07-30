<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_with_temporary_password_is_forced_to_change_it(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('password.change.show'));
    }

    public function test_changing_password_clears_the_flag_and_grants_access(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($user)
            ->post(route('password.change.update'), [
                'current_password' => 'password',
                'password' => 'NewPass@2026',
                'password_confirmation' => 'NewPass@2026',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($user)
            ->post(route('password.change.update'), [
                'current_password' => 'wrong-password',
                'password' => 'NewPass@2026',
                'password_confirmation' => 'NewPass@2026',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->must_change_password);
    }
}
