<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Pre-DPC's numeric boxes take whole numbers only, never negative
 * (2026-10-28) — on the page and on the server.
 */
class WholeNumberFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_numeric_boxes_are_whole_and_never_negative(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $admin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();
        $ao = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create();
        $ah = User::factory()->assignedTo($department, Role::AdministrativeHead)->create();
        $post = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $proposal = app(PreDpcProposalService::class)->createDraft($department, $admin, ['dpc_year' => 2026, 'promotion_to_post_id' => $post->id]);
        app(ProposalAllotmentService::class)->allot($proposal, $ao, $ah, $admin);

        $page = (string) $this->actingAs($admin)->get(route('pre-dpc.edit', $proposal))->assertOk()->getContent();
        $this->assertStringNotContainsString('step="0.01"', $page);
        preg_match_all('#<input type="number"[^>]*>#', $page, $boxes);
        foreach (array_filter($boxes[0], fn (string $box): bool => ! str_contains($box, 'readonly')) as $box) {
            $this->assertStringContainsString('data-whole-number', $box);
            $this->assertStringContainsString('min="0"', $box);
        }
        $this->assertStringContainsString('id="pre-dpc-summary"', $page);

        foreach (['12.5', '-5'] as $value) {
            $this->put(route('pre-dpc.update', $proposal), ['dpc_year' => 2026, 'promotion_to_post_id' => $post->id, 'direct_recruitment_pct' => $value, 'promotion_recruitment_pct' => '100'])
                ->assertSessionHasErrors('direct_recruitment_pct');
        }
    }

    /**
     * Direct Recruitment % and Promotion % fill each other to 100, and the
     * Promotion To Post is never offered as a Promotion From Post (2026-10-29).
     */
    public function test_the_shares_complement_each_other_and_the_to_post_is_not_a_from_post(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $admin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Assistant Director', 'class_level' => 'II', 'status' => MasterStatus::Active->value]);
        $proposal = app(PreDpcProposalService::class)->createDraft($department, $admin, ['dpc_year' => 2026, 'promotion_to_post_id' => $to->id]);

        $page = (string) $this->actingAs($admin)->get(route('pre-dpc.edit', $proposal))->assertOk()->getContent();
        $this->assertStringContainsString('data-complement="promotion_recruitment_pct"', $page);
        $this->assertStringContainsString('data-complement="direct_recruitment_pct"', $page);

        preg_match_all('#<select[^>]*class="[^"]*source-post[^"]*"[^>]*>(.*?)</select>#s', $page, $selects);
        $this->assertNotEmpty($selects[1]);
        foreach ($selects[1] as $options) {
            $this->assertStringContainsString('value="'.$from->id.'"', $options);
            $this->assertStringNotContainsString('value="'.$to->id.'"', $options);
        }
    }
}
