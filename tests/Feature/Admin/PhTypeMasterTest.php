<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\PhType;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PH Type master (2026-11-05): the Government of India codes for persons with
 * benchmark disabilities, kept by the Administrator like any other master.
 */
class PhTypeMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_the_government_of_india_codes_are_seeded_by_category(): void
    {
        $this->assertSame(23, PhType::query()->count());
        $this->assertSame(['One Leg', 'c'], [PhType::query()->where('code', 'OL')->value('name_en'), PhType::query()->where('code', 'OL')->value('category')]);
        $this->assertSame('One Arm and One Leg', PhType::query()->where('code', 'OAL')->value('name_en'));
        $this->assertSame(['B', 'LV'], PhType::query()->where('category', 'a')->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_the_administrator_adds_edits_and_deletes_a_type(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ph-types.index'))->assertOk()->assertSee('OAL')->assertSee('One Arm and One Leg');

        // Stored in capitals, so "ol" is the existing OL.
        $this->post(route('admin.ph-types.store'), ['code' => 'ol', 'name_en' => 'X', 'name_hi' => 'X', 'sort_order' => 1, 'status' => 'active'])
            ->assertSessionHasErrors('code');

        $this->post(route('admin.ph-types.store'), ['code' => 'olx', 'name_en' => 'Other Leg', 'name_hi' => 'अन्य', 'category' => 'c', 'sort_order' => 999, 'status' => 'active'])
            ->assertRedirect(route('admin.ph-types.index'));
        $type = PhType::query()->where('code', 'OLX')->sole();

        $this->put(route('admin.ph-types.update', $type), ['code' => 'OLX', 'name_en' => 'Other Leg (edited)', 'name_hi' => 'अन्य', 'category' => 'z', 'sort_order' => 999, 'status' => 'active'])
            ->assertSessionHasErrors('category');
        $this->put(route('admin.ph-types.update', $type), ['code' => 'OLX', 'name_en' => 'Other Leg (edited)', 'name_hi' => 'अन्य', 'sort_order' => 999, 'status' => 'inactive'])
            ->assertRedirect(route('admin.ph-types.index'));
        $this->assertSame([MasterStatus::Inactive, 'Other Leg (edited)'], [$type->fresh()->status, $type->fresh()->name_en]);
        $this->assertFalse(PhType::query()->selectable()->whereKey($type->id)->exists(), 'A withdrawn type is not offered.');

        // Deleted, and the code can be entered again (2026-11-03).
        $this->delete(route('admin.ph-types.destroy', $type))->assertRedirect(route('admin.ph-types.index'));
        $this->assertSoftDeleted($type);
        $this->post(route('admin.ph-types.store'), ['code' => 'OLX', 'name_en' => 'Other Leg', 'name_hi' => 'अन्य', 'sort_order' => 999, 'status' => 'active'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_menu_links_the_master(): void
    {
        $this->actingAs($this->admin())->get(route('admin.ph-types.index'))->assertOk()
            ->assertSee(route('admin.ph-types.index'), false)
            ->assertSee(__('ui.layouts.admin.nav.ph_types'));
    }
}
