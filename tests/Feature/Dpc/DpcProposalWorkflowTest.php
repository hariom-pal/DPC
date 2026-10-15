<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\Signature\Models\Signature;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

class DpcProposalWorkflowTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DepartmentPost $postFrom;

    private DepartmentPost $postTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $this->postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): DpcProposal
    {
        return $this->raiseDpc($this->ao, $this->postFrom, $this->postTo, $overrides);
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
        // Change item 18: the number was issued when the draft was raised, so
        // approval finds it already there rather than minting it.
        $this->assertSame('DPC-2026-HE-0001', $proposal->dpc_code);

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
        $first = $this->draft();
        $this->makeProposalSubmittable($first);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $first));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $first));

        // A second DPC for the same exercise — item 19's "additional vacancies"
        // case. Raised under the same order, and therefore a new number.
        $second = $this->draft();
        $this->makeProposalSubmittable($second);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $second));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $second));

        $this->assertSame('DPC-2026-HE-0001', $first->fresh()->dpc_code);
        $this->assertSame('DPC-2026-HE-0002', $second->fresh()->dpc_code);
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

    public function test_review_and_circulation_types_are_rejected_here(): void
    {
        $reviewType = DpcType::query()->where('code', 'review')->firstOrFail();
        $preDpc = $this->sanctioningPreDpc($this->postFrom, $this->postTo);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($preDpc, ['dpc_type_id' => $reviewType->id]),
        )->assertSessionHasErrors('dpc_type_id');

        $this->assertSame(0, DpcProposal::query()->count());
    }

    /**
     * Change item 11. The post pair is no longer chosen on this screen, so the
     * rules about *which* pair is legitimate moved to the Pre-DPC and are asserted
     * in `PreDpcExerciseTest`. What this screen enforces is which sanctioning
     * order may be raised under, and that the inheritance actually happens.
     */
    public function test_the_proposal_inherits_everything_the_pre_dpc_settled(): void
    {
        $preDpc = $this->sanctioningPreDpc($this->postFrom, $this->postTo);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($preDpc))
            ->assertRedirect();

        $proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->assertSame($preDpc->id, $proposal->pre_dpc_proposal_id);
        $this->assertSame($preDpc->dpc_year, $proposal->dpc_year);
        $this->assertSame($this->postFrom->id, $proposal->promotion_from_post_id);
        $this->assertSame($this->postTo->id, $proposal->promotion_to_post_id);
        $this->assertSame($preDpc->pay_scale_id, $proposal->pay_scale_id);
        $this->assertSame($preDpc->class_level?->value, $proposal->class_level?->value);
        $this->assertSame($preDpc->promotion_duration_years, $proposal->promotion_duration_years);
    }

    /**
     * Change item 12: the Proposal Date is always generated.
     */
    public function test_the_proposal_date_is_generated_and_cannot_be_supplied(): void
    {
        $preDpc = $this->sanctioningPreDpc($this->postFrom, $this->postTo);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($preDpc, ['proposal_date' => '2001-01-01']),
        )->assertRedirect();

        $proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->assertTrue(
            $proposal->proposal_date->isToday(),
            'The Proposal Date must be generated, not taken from the request.',
        );
    }

    /**
     * Change item 18: the number exists from creation, so a proposal that has
     * never been approved can still be cited.
     */
    public function test_the_dpc_number_is_issued_at_creation_and_revision_one_is_opened(): void
    {
        $proposal = $this->draft();

        $this->assertSame('DPC-2026-HE-0001', $proposal->dpc_code);
        $this->assertSame(1, $proposal->revision_number);
        $this->assertSame(1, $proposal->revisions()->count());
    }

    /**
     * Change item 18: a return bumps the revision and leaves the number alone.
     */
    public function test_a_return_bumps_the_revision_and_keeps_the_number(): void
    {
        $proposal = $this->draft();
        $number = $proposal->dpc_code;

        $this->makeProposalSubmittable($proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));
        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $proposal), ['return_remarks' => 'Correct the CR entries.']);

        $proposal->refresh();

        $this->assertSame($number, $proposal->dpc_code, 'A return must never change the DPC Number.');
        $this->assertSame(2, $proposal->revision_number);
        $this->assertSame(2, $proposal->revisions()->count());

        $first = $proposal->revisions()->where('revision_number', 1)->sole();
        $this->assertSame('Correct the CR entries.', $first->return_reason);
        $this->assertNotNull($first->returned_at);
        $this->assertSame($this->ah->id, $first->returned_by);
        $this->assertSame($this->ao->id, $first->submitted_by);
    }

    /**
     * A closed revision is history. The register requires previous revisions to be
     * read-only for audit, and a history the application can edit is not a history.
     */
    public function test_a_superseded_revision_cannot_be_edited(): void
    {
        $proposal = $this->draft();

        $this->makeProposalSubmittable($proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));
        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $proposal), ['return_remarks' => 'Fix it.']);

        $first = $proposal->refresh()->revisions()->where('revision_number', 1)->sole();

        $this->expectException(LogicException::class);
        $first->forceFill(['return_reason' => 'Something else entirely'])->save();
    }

    public function test_a_revision_cannot_be_deleted(): void
    {
        $proposal = $this->draft();

        $this->expectException(LogicException::class);
        $proposal->revisions()->sole()->delete();
    }

    public function test_a_pre_dpc_that_is_not_approved_cannot_be_raised_under(): void
    {
        $preDpc = $this->sanctioningPreDpc($this->postFrom, $this->postTo);
        $preDpc->forceFill(['status' => 'draft', 'active_flag' => null])->save();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($preDpc))
            ->assertSessionHasErrors('pre_dpc_proposal_id');

        $this->assertSame(0, DpcProposal::query()->count());
    }

    public function test_a_superseded_pre_dpc_cannot_be_raised_under(): void
    {
        $preDpc = $this->sanctioningPreDpc($this->postFrom, $this->postTo);

        // Approved, but no longer the order in force for its exercise (item 9).
        $preDpc->forceFill(['active_flag' => null])->save();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($preDpc))
            ->assertSessionHasErrors('pre_dpc_proposal_id');

        $this->assertSame(0, DpcProposal::query()->count());
    }

    public function test_another_departments_pre_dpc_cannot_be_raised_under(): void
    {
        $other = Department::query()->create(['code' => 'ME', 'name' => 'Medical Education Department', 'status' => 'active']);
        $from = DepartmentPost::query()->create(['department_id' => $other->id, 'name' => 'Lecturer', 'class_level' => 'II', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $other->id, 'name' => 'Assistant Professor', 'class_level' => 'I', 'status' => 'active']);

        $foreign = $this->sanctioningPreDpc($from, $to);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($foreign))
            ->assertSessionHasErrors('pre_dpc_proposal_id');

        $this->assertSame(0, DpcProposal::query()->count());
    }

    public function test_ao_cannot_view_another_departments_proposal(): void
    {
        $proposal = $this->draft();

        $otherDepartment = Department::query()->create(['code' => 'ME', 'name' => 'Medical Education Department', 'status' => 'active']);
        $otherAo = User::factory()->assignedTo($otherDepartment->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($otherAo)->get(route('dpc-proposals.show', $proposal))->assertForbidden();
    }
}
