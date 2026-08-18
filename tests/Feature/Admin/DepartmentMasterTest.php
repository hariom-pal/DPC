<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department Master maintenance, and the two guards that make it safe to expose:
 * the code is the DPC ID prefix, and a department that anything already
 * references is withdrawn rather than deleted.
 */
class DepartmentMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    private function district(): District
    {
        return District::query()->create([
            'name_en' => 'Raipur', 'name_hi' => 'रायपुर', 'state_lgd_code' => '22',
            'status' => MasterStatus::Active->value,
        ]);
    }

    public function test_admin_can_create_a_department(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'code' => 'wrd',
                'name' => 'Water Resources Department',
                'district_id' => $this->district()->id,
                'place' => 'Naya Raipur',
                'pin' => '492002',
                'email' => 'wrd@cg.gov.in',
                'status' => DepartmentStatus::Unregistered->value,
            ])
            ->assertRedirect(route('admin.departments.index'));

        $department = Department::query()->where('name', 'Water Resources Department')->firstOrFail();

        // Lower case in, upper case stored: the code is a DPC ID prefix.
        $this->assertSame('WRD', $department->code);
        $this->assertSame(DepartmentStatus::Unregistered, $department->status);
    }

    public function test_department_code_and_name_must_be_unique(): void
    {
        $admin = $this->admin();
        Department::query()->create(['code' => 'SE', 'name' => 'School Education Department', 'status' => 'unregistered']);

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'code' => 'SE',
                'name' => 'School Education Department',
                'status' => DepartmentStatus::Unregistered->value,
            ])
            ->assertSessionHasErrors(['code', 'name']);
    }

    public function test_department_code_must_be_capitals_and_digits_only(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), [
                'code' => 'SE-1',
                'name' => 'School Education Department',
                'status' => DepartmentStatus::Unregistered->value,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_an_unregistered_department_with_no_references_can_be_deleted(): void
    {
        $admin = $this->admin();
        $department = Department::query()->create(['code' => 'TMP', 'name' => 'Temporary Department', 'status' => 'unregistered']);

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertRedirect(route('admin.departments.index'));

        $this->assertSoftDeleted($department);
    }

    public function test_a_registered_department_is_not_deletable(): void
    {
        $admin = $this->admin();
        $department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education Department',
            'status' => DepartmentStatus::Registered->value, 'registered_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertNotSoftDeleted($department);
    }

    public function test_a_department_with_officer_accounts_is_not_deletable(): void
    {
        $admin = $this->admin();
        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education Department', 'status' => 'unregistered']);
        User::factory()->create(['department_id' => $department->id]);

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertNotSoftDeleted($department);
    }

    public function test_the_code_cannot_be_changed_once_a_dpc_id_has_been_issued(): void
    {
        $admin = $this->admin();
        $department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education Department',
            'status' => DepartmentStatus::Registered->value,
        ]);
        $department->dpcIdSequences()->create(['year' => 2026, 'last_serial' => 1]);

        $this->actingAs($admin)
            ->put(route('admin.departments.update', $department), [
                'code' => 'SED',
                'name' => 'School Education Department',
                'status' => DepartmentStatus::Registered->value,
            ])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertSame('SE', $department->refresh()->code);
    }

    public function test_registration_offers_only_unregistered_departments_from_the_master(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Department::query()->create(['code' => 'SE', 'name' => 'School Education Department', 'status' => 'unregistered']);
        Department::query()->create([
            'code' => 'FIN', 'name' => 'Finance Department',
            'status' => DepartmentStatus::Registered->value,
        ]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('School Education Department')
            ->assertDontSee('Finance Department');
    }

    public function test_non_admin_cannot_reach_the_department_master(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.departments.index'))->assertForbidden();
    }
}
