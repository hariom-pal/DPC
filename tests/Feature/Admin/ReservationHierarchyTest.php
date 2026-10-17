<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationRoster;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\DepartmentPostSeeder;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class, LookupMasterSeeder::class, DepartmentPostSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_post(): void
    {
        $admin = $this->admin();
        $department = Department::query()->where('code', 'HE')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.department-posts.store'), [
                'department_id' => $department->id,
                'name' => 'Additional Director',
                'class_level' => ServiceClass::I->value,
                'status' => MasterStatus::Active->value,

                // A mapping is effective-dated since 2026-08-25 — it is what a
                // Government Order authorises from a date.
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors()
            // The details page, which is where the Government Order and its PDF
            // are shown.
            ->assertRedirect(route('admin.department-posts.show', DepartmentPost::query()->latest('id')->firstOrFail()));

        $this->assertDatabaseHas('department_posts', ['name' => 'Additional Director', 'department_id' => $department->id]);
    }

    public function test_admin_can_create_a_roster_and_add_points_within_size(): void
    {
        $admin = $this->admin();
        $department = Department::query()->where('code', 'HE')->firstOrFail();
        $post = DepartmentPost::query()->where('department_id', $department->id)->firstOrFail();
        $category = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.reservation-rosters.store'), [
            'department_id' => $department->id,
            'post_id' => $post->id,
            'name' => 'US-to-DS 100-point',
            'size' => 10,
            'status' => MasterStatus::Active->value,
        ]);

        $roster = ReservationRoster::query()->where('name', 'US-to-DS 100-point')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.reservation-rosters.points.store', $roster), [
                'point_no' => 1,
                'reservation_category_id' => $category->id,
            ])
            ->assertRedirect(route('admin.reservation-rosters.edit', $roster));

        $this->assertDatabaseHas('reservation_roster_points', ['reservation_roster_id' => $roster->id, 'point_no' => 1]);
    }

    public function test_roster_point_beyond_size_is_rejected(): void
    {
        $admin = $this->admin();
        $department = Department::query()->where('code', 'HE')->firstOrFail();
        $post = DepartmentPost::query()->where('department_id', $department->id)->firstOrFail();
        $category = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $roster = ReservationRoster::query()->create([
            'department_id' => $department->id, 'post_id' => $post->id,
            'name' => 'Small', 'size' => 5, 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.reservation-rosters.points.store', $roster), [
                'point_no' => 6,
                'reservation_category_id' => $category->id,
            ])
            ->assertSessionHasErrors('point_no');
    }

    public function test_admin_can_create_a_reservation_policy(): void
    {
        $admin = $this->admin();
        $category = ReservationCategory::query()->where('code', 'ST')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.reservation-policies.store'), [
                'reservation_category_id' => $category->id,
                'percentage' => '32',
                'effective_from' => '2020-01-01',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.reservation-policies.index'));

        $this->assertDatabaseHas('reservation_policies', ['reservation_category_id' => $category->id]);
    }
}
