<?php

declare(strict_types=1);

namespace Tests\Feature\Allotment;

use App\Domain\Allotment\Exceptions\AllotmentRefused;
use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A proposal's AO and AH are fixed once it carries a signature or is approved or
 * cancelled (2026-10-27): reassignment never rewrites who prepared and who signed.
 */
class ReassignmentAfterSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $ao;

    private User $ah;

    private User $otherAo;

    private User $admin;

    private PreDpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::query()->create(['code' => 'PHE', 'name' => 'Public Health Engineering', 'status' => 'registered']);
        $this->ao = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($department, Role::AdministrativeHead)->create();
        $this->otherAo = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create();
        $this->admin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();

        $post = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Executive Engineer', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $this->proposal = app(PreDpcProposalService::class)->createDraft($department, $this->ao, ['dpc_year' => 2026, 'promotion_to_post_id' => $post->id]);
        app(ProposalAllotmentService::class)->allot($this->proposal, $this->ao->fresh(), $this->ah->fresh(), $this->admin);
    }

    private function reassign(): ProposalAllotment
    {
        return app(ProposalAllotmentService::class)->allot($this->proposal->fresh(), $this->otherAo->fresh(), $this->ah->fresh(), $this->admin, 'Officer on leave');
    }

    public function test_a_draft_or_returned_proposal_can_be_reassigned_with_history(): void
    {
        $this->proposal->forceFill(['status' => PreDpcStatus::Returned->value])->save();

        $this->assertSame($this->otherAo->id, $this->reassign()->ao_user_id);
        $this->assertSame(2, ProposalAllotment::query()->where('pre_dpc_proposal_id', $this->proposal->id)->count());
        $this->assertDatabaseHas('activity_timelines', ['event' => 'proposal.reallotted', 'subject_id' => $this->proposal->id]);
    }

    public function test_an_approved_or_cancelled_proposal_is_never_reassigned(): void
    {
        foreach ([PreDpcStatus::Approved, PreDpcStatus::Cancelled] as $status) {
            $this->proposal->forceFill(['status' => $status->value])->save();

            try {
                $this->reassign();
                $this->fail("Reassigned a {$status->value} proposal.");
            } catch (AllotmentRefused $e) {
                $this->assertSame(__('allotments.errors.locked_after_signature'), $e->getMessage());
            }
        }

        $this->assertSame($this->ao->id, ProposalAllotment::query()->current()->where('pre_dpc_proposal_id', $this->proposal->id)->sole()->ao_user_id);
    }

    /**
     * While submitted, the AO whose signature stands stays; an AH who has not
     * signed may still be replaced (2026-10-28).
     */
    public function test_a_submitted_proposal_keeps_its_signing_ao_but_may_change_its_ah(): void
    {
        $this->proposal->forceFill(['status' => PreDpcStatus::Submitted->value])->save();
        $otherAh = User::factory()->assignedTo($this->proposal->department_id, Role::AdministrativeHead)->create();

        try {
            $this->reassign();
            $this->fail('The AO who signed the submission was replaced.');
        } catch (AllotmentRefused $e) {
            $this->assertSame(__('allotments.errors.locked_after_signature'), $e->getMessage());
        }

        $allotment = app(ProposalAllotmentService::class)->allot($this->proposal->fresh(), $this->ao->fresh(), $otherAh->fresh(), $this->admin, 'eSign name did not match');
        $this->assertSame($otherAh->id, $allotment->ah_user_id);
    }
}
