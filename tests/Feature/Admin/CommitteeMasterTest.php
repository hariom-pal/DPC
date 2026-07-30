<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_committee_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.committee-types.store'), [
                'code' => 'class_i_dpc',
                'name_en' => 'Class-I DPC Committee',
                'name_hi' => 'वर्ग-1 डी.पी.सी. समिति',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.committee-types.index'));

        $this->assertDatabaseHas('committee_types', ['code' => 'class_i_dpc']);
    }

    public function test_admin_can_create_a_committee_configuration_with_all_parameters(): void
    {
        $admin = $this->admin();
        $type = CommitteeType::query()->create([
            'code' => 'class_i_dpc', 'name_en' => 'Class-I DPC', 'name_hi' => 'वर्ग-1', 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.committee-configurations.store'), [
                'committee_type_id' => $type->id,
                'min_members' => 3,
                'max_members' => 5,
                'chairman_mandatory' => '1',
                'quorum' => 3,
                'voting_method' => 'Open',
                'majority_type' => 'Simple',
                'tie_break_rule' => 'Chairman casting vote',
                'digital_signature_required' => '1',
                'secretary_required' => '1',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.committee-configurations.index'));

        $config = CommitteeConfiguration::query()->firstOrFail();
        $this->assertSame(3, $config->min_members);
        $this->assertSame(5, $config->max_members);
        $this->assertTrue($config->chairman_mandatory);
        $this->assertTrue($config->secretary_required);
        $this->assertFalse($config->remarks_required);
        $this->assertFalse($config->external_member_allowed);
    }

    public function test_quorum_cannot_exceed_max_members(): void
    {
        $admin = $this->admin();
        $type = CommitteeType::query()->create([
            'code' => 't', 'name_en' => 'T', 'name_hi' => 'ट', 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.committee-configurations.store'), [
                'committee_type_id' => $type->id,
                'min_members' => 3,
                'max_members' => 5,
                'chairman_mandatory' => '1',
                'quorum' => 6,
                'voting_method' => 'Open',
                'majority_type' => 'Simple',
                'tie_break_rule' => 'Chairman casting vote',
                'digital_signature_required' => '1',
                'status' => MasterStatus::Active->value,
            ])
            ->assertSessionHasErrors('quorum');
    }
}
