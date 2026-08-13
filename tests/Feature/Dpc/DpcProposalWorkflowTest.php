<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\Signature\Models\Signature;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

class DpcProposalWorkflowTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private Cadre $postFrom;

    private Cadre $postTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);
        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $this->postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): DpcProposal
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), array_merge([
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->postTo->id,
            'post_from_cadre_id' => $this->postFrom->id,
            'proposal_date' => '2026-06-25',
            'legal_stay' => '0',
        ], $overrides));

        return DpcProposal::query()->latest('id')->firstOrFail();
    }

    public function test_ao_can_create_and_submit_a_draft(): void
    {
        $proposal = $this->draft();
        $this->assertSame(DpcProposalStatus::Draft, $proposal->status);

        $this->makeProposalSubmittable($proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal))
            ->assertRedirect(route('dpc-proposals.show', $proposal));

        $this->assertSame(DpcProposalStatus::Submitted, $proposal->fresh()->status);
        $this->assertDatabaseHas('activity_timelines', ['event' => 'dpc_proposal.submit', 'subject_id' => $proposal->id]);
    }

    public function test_ah_approval_is_a_single_transition_with_one_signature_and_generates_dpc_id(): void
    {
        // owen-it/laravel-auditing treats PHPUnit's CLI process as "console"
        // and skips recording by default (config/audit.php `console` =>
        // false) — enable it for this assertion only, to prove the audit
        // trail really fires once per approval rather than assuming it.
        config(['audit.console' => true]);

        Storage::fake('local');
        $proposal = $this->draft();
        $this->makeProposalSubmittable($proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $proposal))
            ->assertRedirect(route('dpc-proposals.show', $proposal));

        $proposal->refresh();
        $this->assertSame(DpcProposalStatus::Approved, $proposal->status);
        $this->assertNotNull($proposal->approved_at);
        $this->assertNotNull($proposal->forwarded_at);
        $this->assertNotNull($proposal->signature_id);
        $this->assertSame('HE'.now()->format('Y').'00001', $proposal->dpc_code);

        // Decision 1 — exactly one of each, not five.
        $this->assertSame(1, Signature::query()->where('signable_type', $proposal->getMorphClass())->where('signable_id', $proposal->id)->count());
        $this->assertSame(1, WorkflowTransition::query()->where('workflow', 'dpc_proposal')->where('event', 'approve')->where('subject_id', $proposal->id)->count());
        $this->assertSame(1, DB::table('activity_timelines')->where('event', 'dpc_proposal.approve')->where('subject_id', $proposal->id)->count());
        // Exactly one audit row records the approval itself (a separate,
        // equally-legitimate audit row exists for the earlier submit).
        $this->assertSame(1, DB::table('audits')
            ->where('auditable_type', $proposal->getMorphClass())
            ->where('auditable_id', $proposal->id)
            ->where('event', 'updated')
            ->where('new_values', 'like', '%"status":"approved"%')
            ->count());
    }

    public function test_dpc_id_serial_increments_per_department_per_year(): void
    {
        Storage::fake('local');

        $first = $this->draft();
        $this->makeProposalSubmittable($first);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $first));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $first));

        $second = $this->draft(['post_to_cadre_id' => $this->postTo->id, 'post_from_cadre_id' => $this->postFrom->id]);
        $this->makeProposalSubmittable($second);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $second));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $second));

        $this->assertSame('HE'.now()->format('Y').'00001', $first->fresh()->dpc_code);
        $this->assertSame('HE'.now()->format('Y').'00002', $second->fresh()->dpc_code);
    }

    public function test_ao_cannot_approve(): void
    {
        $proposal = $this->draft();
        $this->makeProposalSubmittable($proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));

        $this->actingAs($this->ao)->post(route('dpc-proposals.approve', $proposal))
            ->assertForbidden();

        $this->assertSame(DpcProposalStatus::Submitted, $proposal->fresh()->status);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $proposal = $this->draft(); // still DRAFT

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $proposal))
            ->assertForbidden();

        $this->assertSame(DpcProposalStatus::Draft, $proposal->fresh()->status);
    }

    public function test_ah_can_return_with_remarks(): void
    {
        $proposal = $this->draft();
        $this->makeProposalSubmittable($proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));

        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $proposal), ['return_remarks' => 'Wrong post selected.'])
            ->assertRedirect(route('dpc-proposals.show', $proposal));

        $proposal->refresh();
        $this->assertSame(DpcProposalStatus::Returned, $proposal->status);
        $this->assertSame('Wrong post selected.', $proposal->return_remarks);
    }

    public function test_review_and_circulation_types_are_rejected_for_now(): void
    {
        $reviewType = DpcType::query()->where('code', 'review')->firstOrFail();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => $reviewType->id,
            'post_to_cadre_id' => $this->postTo->id,
            'post_from_cadre_id' => $this->postFrom->id,
            'proposal_date' => '2026-06-25',
        ])->assertSessionHasErrors('dpc_type_id');

        $this->assertSame(0, DpcProposal::query()->count());
    }

    public function test_post_to_and_post_from_must_differ(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->postTo->id,
            'post_from_cadre_id' => $this->postTo->id,
            'proposal_date' => '2026-06-25',
        ])->assertSessionHasErrors('post_from_cadre_id');
    }

    public function test_ao_cannot_view_another_departments_proposal(): void
    {
        $proposal = $this->draft();

        $otherDepartment = Department::query()->create(['code' => 'ME', 'name' => 'Medical Education Department', 'status' => 'active']);
        $otherAo = User::factory()->create(['department_id' => $otherDepartment->id]);
        $otherAo->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($otherAo)->get(route('dpc-proposals.show', $proposal))->assertForbidden();
    }

    public function test_cannot_select_a_cadre_from_another_department(): void
    {
        $otherDepartment = Department::query()->create(['code' => 'ME', 'name' => 'Medical Education Department', 'status' => 'active']);
        $otherCadre = Cadre::query()->create(['department_id' => $otherDepartment->id, 'name' => 'Assistant Professor', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $otherCadre->id,
            'post_from_cadre_id' => $this->postFrom->id,
            'proposal_date' => '2026-06-25',
        ])->assertSessionHasErrors('post_to_cadre_id');
    }
}
