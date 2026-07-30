<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\RecruitmentRule;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\CadreSeeder;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecruitmentRuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class, CadreSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_versioned_rule_with_feeder_and_pdf(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $target = Cadre::query()->where('name', 'Deputy Secretary')->firstOrFail();
        $feeder = Cadre::query()->where('name', 'Under Secretary')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), [
            'cadre_id' => $target->id,
            'government_order_number' => 'GO/2024/HE/12',
            'direct_recruitment_pct' => '0',
            'promotion_recruitment_pct' => '100',
            'effective_from' => '2024-01-01',
            'status' => MasterStatus::Draft->value,
            'go_document' => UploadedFile::fake()->create('go.pdf', 120, 'application/pdf'),
            'feeders' => [
                ['feeder_cadre_id' => $feeder->id, 'promotion_percentage' => '100', 'min_experience_years' => '5', 'min_qualification' => 'Graduate'],
            ],
        ])->assertRedirect(route('admin.recruitment-rules.index'));

        $rule = RecruitmentRule::query()->where('cadre_id', $target->id)->firstOrFail();
        $this->assertSame(1, $rule->version);
        $this->assertSame(1, $rule->feeders()->count());
        $this->assertNotNull($rule->go_document_path);
        Storage::disk('local')->assertExists($rule->go_document_path);
    }

    public function test_new_version_clones_into_a_fresh_draft(): void
    {
        $admin = $this->admin();
        $target = Cadre::query()->where('name', 'Deputy Secretary')->firstOrFail();
        $feeder = Cadre::query()->where('name', 'Under Secretary')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'cadre_id' => $target->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $rule->feeders()->create(['feeder_cadre_id' => $feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]);

        $this->actingAs($admin)->post(route('admin.recruitment-rules.version', $rule))->assertRedirect();

        $v2 = RecruitmentRule::query()->where('cadre_id', $target->id)->where('version', 2)->firstOrFail();
        $this->assertSame(MasterStatus::Draft, $v2->status);
        $this->assertSame(1, $v2->feeders()->count());
    }

    public function test_active_rule_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $target = Cadre::query()->where('name', 'Deputy Secretary')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'cadre_id' => $target->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($admin)->get(route('admin.recruitment-rules.edit', $rule))
            ->assertRedirect(route('admin.recruitment-rules.index'));
    }
}
