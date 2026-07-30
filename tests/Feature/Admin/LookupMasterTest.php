<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Master\Models\VacancyType;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_seeder_populates_all_lookup_masters(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $this->assertSame(5, ReservationCategory::query()->count());
        $this->assertSame(6, ReviewReason::query()->count());
        $this->assertSame(4, VacancyType::query()->count());
        $this->assertSame(3, DpcType::query()->count());
        $this->assertFalse((bool) ReservationCategory::query()->where('code', 'UR')->value('is_reserved'));
    }

    public function test_admin_can_create_a_vacancy_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.vacancy-types.store'), [
                'code' => 'clear',
                'name_en' => 'Clear vacancy',
                'name_hi' => 'स्पष्ट रिक्ति',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.vacancy-types.index'));

        $this->assertDatabaseHas('vacancy_types', ['code' => 'clear']);
    }

    public function test_reservation_category_reserved_flag_persists(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reservation-categories.store'), [
                'code' => 'SC',
                'name_en' => 'Scheduled Caste',
                'name_hi' => 'अनुसूचित जाति',
                'is_reserved' => '1',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.reservation-categories.index'));

        $this->assertTrue((bool) ReservationCategory::query()->where('code', 'SC')->value('is_reserved'));
    }

    public function test_dpc_type_code_must_be_unique(): void
    {
        $admin = $this->admin();
        DpcType::query()->create(['code' => 'fresh', 'name_en' => 'Fresh DPC', 'name_hi' => 'फ्रेश', 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.dpc-types.store'), [
                'code' => 'fresh',
                'name_en' => 'Fresh DPC',
                'name_hi' => 'फ्रेश',
                'status' => MasterStatus::Active->value,
            ])
            ->assertSessionHasErrors('code');
    }
}
