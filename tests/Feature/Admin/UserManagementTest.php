<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_provision_a_member_account(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'date_of_birth' => '1979-09-09', 'gender' => 'M', // checked against DigiLocker (2026-10-27)
                'name' => 'Hon. Member One',
                'email' => 'member1@cgpsc.gov.in',
                'mobile' => '9812345678',
                'role' => Role::Member->value,
            ])
            // The temporary password is shown on the page that answers the POST (2026-10-26).
            ->assertOk()
            ->assertSee('id="issued-password"', false);

        $member = User::query()->where('email', 'member1@cgpsc.gov.in')->firstOrFail();
        $this->assertTrue($member->hasRole(Role::Member->value));
        $this->assertTrue($member->is_active);
        $this->assertTrue($member->must_change_password);
        $this->assertFalse($member->isFullyVerified());
    }

    public function test_provisioning_requires_a_government_email(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'date_of_birth' => '1979-09-09', 'gender' => 'M', // checked against DigiLocker (2026-10-27)
                'name' => 'X', 'email' => 'x@gmail.com', 'mobile' => '9812345678', 'role' => Role::Member->value,
            ])
            ->assertSessionHasErrors('email');
    }

    /**
     * The two roles that are *not* provisionable, and each for its own reason.
     *
     * `individual` is the unauthenticated Self-Inspection actor of S49–S51 and
     * holds no account at all. `promotion_candidate` holds a real login, but the
     * account is created by the Seniority List that names the officer (change
     * item 17) and never typed in by hand — provisioning one here would create a
     * candidate with no DPC to be a candidate in.
     *
     * The two department roles *are* provisionable as of 2026-08-24: change item 1
     * put Users and Role Assignments under the single CGPSC Administrator.
     */
    public function test_a_role_that_cannot_be_provisioned_by_hand_is_rejected(): void
    {
        foreach ([Role::Individual, Role::PromotionCandidate] as $index => $role) {
            $this->actingAs($this->admin())
                ->post(route('admin.users.store'), [
                    'date_of_birth' => '1979-09-09', 'gender' => 'M', // checked against DigiLocker (2026-10-27)
                    'name' => 'X',
                    'email' => "x{$index}@cgpsc.gov.in",
                    'mobile' => '981234567'.$index,
                    'role' => $role->value,
                ])
                ->assertSessionHasErrors('role');
        }
    }

    public function test_an_account_with_an_older_address_can_still_be_edited(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['email' => 'member@nic.in', 'name' => 'Shri Member ']);
        $member->assignRole(Role::Member->value);

        // Unchanged, the address is kept (2026-10-30); a padded name is the same name.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), [
                'name' => 'Shri Member', 'email' => 'member@nic.in', 'mobile' => '9811111111',
                'role' => Role::Member->value, 'department_id' => '', 'designation_id' => '',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('9811111111', $member->fresh()->mobile);

        // A new address must still be .gov.in.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), [
                'name' => 'Shri Member', 'email' => 'member@gmail.com', 'mobile' => '9811111111',
                'role' => Role::Member->value, 'department_id' => '', 'designation_id' => '',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_can_deactivate_and_reactivate_an_account(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $member->assignRole(Role::Member->value);

        $this->actingAs($admin)->post(route('admin.users.toggle-active', $member));
        $this->assertFalse($member->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.users.toggle-active', $member->fresh()));
        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_reset_password_forces_change_on_next_login(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['must_change_password' => false]);
        $member->assignRole(Role::Member->value);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $member));

        $this->assertTrue($member->fresh()->must_change_password);
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.users.index'))->assertForbidden();
    }
}
