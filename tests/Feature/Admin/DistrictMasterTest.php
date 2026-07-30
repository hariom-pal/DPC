<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\District;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_district_and_it_is_logged_to_the_timeline(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.districts.store'), [
                'name_en' => 'Raipur',
                'name_hi' => 'रायपुर',
                'state_lgd_code' => '22',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.districts.index'));

        $district = District::query()->where('name_en', 'Raipur')->firstOrFail();
        $this->assertSame(MasterStatus::Active, $district->status);
        $this->assertTrue(
            ActivityTimeline::query()->where('event', 'master.created')->exists()
        );
    }

    public function test_district_name_must_be_unique(): void
    {
        $admin = $this->admin();
        District::query()->create([
            'name_en' => 'Durg', 'name_hi' => 'दुर्ग', 'state_lgd_code' => '22', 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.districts.store'), [
                'name_en' => 'Durg',
                'name_hi' => 'दुर्ग',
                'state_lgd_code' => '22',
                'status' => MasterStatus::Active->value,
            ])
            ->assertSessionHasErrors('name_en');
    }

    public function test_admin_can_soft_delete_a_district(): void
    {
        $admin = $this->admin();
        $district = District::query()->create([
            'name_en' => 'Korba', 'name_hi' => 'कोरबा', 'state_lgd_code' => '22', 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.districts.destroy', $district))
            ->assertRedirect(route('admin.districts.index'));

        $this->assertSoftDeleted($district);
    }

    public function test_non_admin_cannot_access_districts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.districts.index'))->assertForbidden();
    }
}
