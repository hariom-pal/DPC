<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreDpcWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);
        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);
    }

    private function draft(): PreDpcProposal
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/2024/HE/1',
            'effective_from' => '2024-01-01',
            'posts' => [[
                'post_name' => 'Deputy Secretary',
                'class_level' => 'I',
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                'feeders' => [[
                    'feeder_name' => 'Under Secretary',
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                ]],
            ]],
        ]);

        return PreDpcProposal::query()->latest('id')->firstOrFail();
    }

    public function test_ao_can_create_and_submit_a_draft(): void
    {
        $proposal = $this->draft();
        $this->assertSame(PreDpcStatus::Draft, $proposal->status);
        $this->assertSame(1, $proposal->posts()->count());

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->assertTrue(WorkflowTransition::query()->where('event', 'submit')->where('subject_id', $proposal->id)->exists());
        $this->assertDatabaseHas('activity_timelines', ['event' => 'pre_dpc.submit', 'subject_id' => $proposal->id]);
    }

    public function test_ah_approval_signs_and_projects_to_masters(): void
    {
        Storage::fake('local');
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Approved, $proposal->status);
        $this->assertTrue($proposal->is_current);
        $this->assertNotNull($proposal->signature_id);

        $cadre = Cadre::query()->where('department_id', $this->department->id)->where('name', 'Deputy Secretary')->firstOrFail();
        $this->assertSame(1, RecruitmentRule::query()->where('cadre_id', $cadre->id)->count());
    }

    public function test_ao_cannot_approve(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ao)->post(route('pre-dpc.approve', $proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $proposal = $this->draft(); // still DRAFT

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
    }

    public function test_ah_can_return_with_remarks(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ah)->post(route('pre-dpc.return', $proposal), ['return_remarks' => 'Fix the roster points.'])
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Returned, $proposal->status);
        $this->assertSame('Fix the roster points.', $proposal->return_remarks);
    }

    public function test_amend_creates_a_new_draft_version(): void
    {
        Storage::fake('local');
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        $this->actingAs($this->ao)->post(route('pre-dpc.amend', $proposal->fresh()));

        $v2 = PreDpcProposal::query()->where('department_id', $this->department->id)->where('version', 2)->firstOrFail();
        $this->assertSame(PreDpcStatus::Draft, $v2->status);
        $this->assertSame($proposal->id, $v2->parent_version_id);
        $this->assertSame(1, $v2->posts()->count());
    }

    public function test_submit_requires_go_number(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'posts' => [[
                'post_name' => 'Deputy Secretary', 'class_level' => 'I',
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            ]],
        ]);
        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
    }
}
