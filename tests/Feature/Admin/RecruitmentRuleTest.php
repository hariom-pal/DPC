<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\DepartmentPostSeeder;
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
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class, DepartmentPostSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_versioned_rule_with_feeder_and_pdf(): void
    {
        $admin = $this->admin();
        $target = DepartmentPost::query()->where('name', 'Deputy Secretary')->firstOrFail();
        $feeder = DepartmentPost::query()->where('name', 'Under Secretary')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), [
            'post_id' => $target->id,
            'government_order_number' => 'GO/2024/HE/12',
            'direct_recruitment_pct' => '0',
            'promotion_recruitment_pct' => '100',
            'effective_from' => '2024-01-01',
            'status' => MasterStatus::Draft->value,
            'go_document' => UploadedFile::fake()->create('go.pdf', 120, 'application/pdf'),
            'feeders' => [
                ['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => '100', 'min_experience_years' => '5', 'min_qualification' => 'Graduate'],
            ],
        ])->assertRedirect(route('admin.recruitment-rules.index'));

        $rule = RecruitmentRule::query()->where('post_id', $target->id)->firstOrFail();
        $this->assertSame(1, $rule->version);
        $this->assertSame(1, $rule->promotionSources()->count());
        $this->assertNotNull($rule->go_document_path);
        Storage::disk('local')->assertExists($rule->go_document_path);
    }

    public function test_new_version_clones_into_a_fresh_draft(): void
    {
        $admin = $this->admin();
        $target = DepartmentPost::query()->where('name', 'Deputy Secretary')->firstOrFail();
        $feeder = DepartmentPost::query()->where('name', 'Under Secretary')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $target->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $rule->promotionSources()->create(['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]);

        $this->actingAs($admin)->post(route('admin.recruitment-rules.version', $rule))->assertRedirect();

        $v2 = RecruitmentRule::query()->where('post_id', $target->id)->where('version', 2)->firstOrFail();
        $this->assertSame(MasterStatus::Draft, $v2->status);
        $this->assertSame(1, $v2->promotionSources()->count());
    }

    /**
     * The feeder grid's qualification field is a searchable checkbox multiselect
     * (business instruction, 2026-09-06).
     *
     * It carried `data-searchable` until that date — the *single-value*
     * enhancement, which assigns `select.value` and so collapses a multiple select
     * to one option. A feeder that accepts three qualifications could not be
     * recorded through it, and nothing failed: the form still submitted, with one
     * qualification instead of three. That is why the wrong attribute is asserted
     * absent rather than merely the right one asserted present.
     */
    public function test_the_feeder_qualification_field_is_a_searchable_checkbox_multiselect(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.recruitment-rules.create'))
            ->assertOk();

        $response->assertSee('data-multiselect', false);
        $response->assertSee('name="feeders[__INDEX__][qualification_ids][]"', false);
        $response->assertDontSee('data-searchable-placeholder="'.__('admin.recruitment_rules.partials.feeder_row.search_qualifications').'"', false);
    }

    public function test_active_rule_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $target = DepartmentPost::query()->where('name', 'Deputy Secretary')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $target->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($admin)->get(route('admin.recruitment-rules.edit', $rule))
            ->assertRedirect(route('admin.recruitment-rules.index'));
    }
}
