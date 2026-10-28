<?php

declare(strict_types=1);

namespace Tests\Feature\Assignments;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\ActingAssignmentService;
use App\Domain\UserManagement\Services\AssignmentRequestService;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One officer, several department assignments (Phase 6, 2026-10-27): AO in one
 * department and AH in another, each department's work isolated, the acting
 * department chosen and re-checked on every request.
 */
class MultiDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $alpha;

    private Department $beta;

    private User $officer;

    private PreDpcProposal $alphaProposal;

    private PreDpcProposal $betaProposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->alpha = Department::query()->create(['code' => 'ALPHA', 'name' => 'Alpha Department', 'status' => 'registered']);
        $this->beta = Department::query()->create(['code' => 'BETA', 'name' => 'Beta Department', 'status' => 'registered']);

        // AO of Alpha (primary posting) and, by additional charge, AH of Beta.
        $this->officer = User::factory()->assignedTo($this->alpha, Role::AuthorizedOfficer)->create(['must_change_password' => false]);
        app(DepartmentAssignmentService::class)->addAdditionalCharge($this->officer, $this->beta, Role::AdministrativeHead, Carbon::today(), null, null);

        $alphaAh = User::factory()->assignedTo($this->alpha, Role::AdministrativeHead)->create();
        $betaAo = User::factory()->assignedTo($this->beta, Role::AuthorizedOfficer)->create();

        $this->alphaProposal = $this->proposal($this->alpha, $this->officer, $alphaAh);
        $this->betaProposal = $this->proposal($this->beta, $betaAo, $this->officer);
    }

    private function proposal(Department $department, User $ao, User $ah): PreDpcProposal
    {
        $post = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Post of '.$department->code, 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $proposal = app(PreDpcProposalService::class)->createDraft($department, $ao, ['dpc_year' => 2026, 'promotion_to_post_id' => $post->id]);
        app(ProposalAllotmentService::class)->allot($proposal, $ao->fresh(), $ah->fresh(), null);

        return $proposal;
    }

    private function assignmentIn(Department $department): UserDepartmentAssignment
    {
        return UserDepartmentAssignment::query()->current()->where('user_id', $this->officer->id)->where('department_id', $department->id)->sole();
    }

    public function test_one_account_holds_ao_in_one_department_and_ah_in_another(): void
    {
        $this->assertEqualsCanonicalizing([Role::AdministrativeHead->value, Role::AuthorizedOfficer->value], $this->officer->fresh()->grantedRoleNames());
        $this->assertCount(2, app(ActingAssignmentService::class)->current($this->officer));
        $this->assertSame(1, User::query()->where('email', $this->officer->email)->count(), 'One account.');
    }

    public function test_each_dpc_is_reached_in_the_role_it_was_allotted_in(): void
    {
        // Authority is per DPC (2026-10-27): the officer reaches both DPCs they are
        // allotted to, in the role each allotment gives, and nothing else.
        $this->actingAs($this->officer)->get(route('pre-dpc.show', $this->alphaProposal))->assertOk();
        $this->get(route('pre-dpc.show', $this->betaProposal))->assertOk();
        $this->get(route('pre-dpc.edit', $this->alphaProposal))->assertOk();
        $this->get(route('pre-dpc.edit', $this->betaProposal))->assertForbidden(); // AH in Beta, never AO there

        // The department switcher still records the acting department.
        $this->post(route('acting-department'), ['assignment_id' => $this->assignmentIn($this->beta)->id])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('security_events', ['user_id' => $this->officer->id, 'event' => 'assignment.switched']);
    }

    public function test_the_same_department_never_holds_the_officer_twice(): void
    {
        $this->expectException(AssignmentRefused::class);
        app(DepartmentAssignmentService::class)->addAdditionalCharge($this->officer, $this->alpha, Role::AdministrativeHead, Carbon::today(), null, null);
    }

    public function test_an_expired_additional_charge_confers_nothing_even_if_the_session_still_points_at_it(): void
    {
        $beta = $this->assignmentIn($this->beta);
        $this->actingAs($this->officer)->post(route('acting-department'), ['assignment_id' => $beta->id]);
        $this->get(route('pre-dpc.show', $this->betaProposal))->assertOk();

        // The end date passes: the switcher refuses it at once, and the daily run
        // ends the charge and holds its DPC work (2026-10-27: DPC authority comes
        // from the allotment, which the hold takes away).
        $beta->forceFill(['effective_to' => Carbon::yesterday()])->save();
        $this->post(route('acting-department'), ['assignment_id' => $beta->id])->assertForbidden();

        $this->artisan('dpc:process-assignments')->assertSuccessful();
        $this->get(route('pre-dpc.show', $this->betaProposal))->assertForbidden();
    }

    public function test_an_officer_cannot_switch_to_someone_elses_assignment(): void
    {
        $other = UserDepartmentAssignment::query()->where('user_id', '!=', $this->officer->id)->firstOrFail();

        $this->actingAs($this->officer)->post(route('acting-department'), ['assignment_id' => $other->id])->assertForbidden();
    }

    public function test_ending_the_additional_charge_takes_its_role_and_access_away(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::Admin->value);
        app(AssignmentRequestService::class)->endAdditionalCharge($this->assignmentIn($this->beta), $superAdmin, 'Charge relinquished');

        $this->assertSame([Role::AuthorizedOfficer->value], $this->officer->fresh()->grantedRoleNames());
        $this->actingAs($this->officer->fresh())->get(route('pre-dpc.show', $this->betaProposal))->assertForbidden();
        $this->get(route('pre-dpc.show', $this->alphaProposal))->assertOk();
        $this->assertDatabaseHas('activity_timelines', ['event' => 'user.assignment_ended', 'subject_id' => $this->officer->id]);
    }
}
