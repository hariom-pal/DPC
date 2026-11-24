<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * MoM of 16 November 2026, points 2 and 3: a DPC Committee member's decision, and a
 * section-wise return that opens only those sections to the AO and goes straight
 * back to the committee.
 */
class CommitteeDecisionTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private User $ao;

    private User $ah;

    private User $member;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk'));

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($department->id, Role::AdministrativeHead)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Under Secretary'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());
        $this->row = $this->proposal->evaluationRows()->sole();

        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        // With the committee: the AH and the Secretary have approved.
        $this->proposal->forceFill(['status' => DpcProposalStatus::Approved->value])->save();

        $this->member = User::factory()->create();
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->serving()->whereNull('user_id')->orderByDesc('sort_order')->firstOrFail()
            ->forceFill(['user_id' => $this->member->id])->save();
        // Allotted by the CGPSC Chairman (2026-11-24) — to somebody else until makeChairman().
        $this->allotForTest($this->proposal, User::factory()->create());
    }

    /** The DPC Chairman of this DPC, by the seat designation (a DPC never allotted). */
    private function makeChairman(User $user): void
    {
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->update(['is_dpc_chairman' => null]);
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('user_id', $user->id)->update(['is_dpc_chairman' => 1]);
        // The DPC Chairman is the allottee once a DPC is allotted.
        $this->allotForTest($this->proposal, $user);
    }

    /** 2026-11-18: only the DPC Chairman accepts or rejects the DPC, once a visit. */
    public function test_only_the_dpc_chairman_accepts_the_dpc(): void
    {
        // A member who is not the DPC Chairman has no decision to make.
        $this->actingAs($this->member)->get(route('sitting.show', $this->proposal))->assertOk()->assertDontSee('name="decision"', false);
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed'])->assertForbidden();

        $this->makeChairman($this->member);
        $this->get(route('sitting.show', $this->proposal))->assertSee('name="decision"', false)->assertDontSee('name="sections[]"', false);
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed', 'remarks' => 'In order.'])->assertRedirect(route('sitting.show', $this->proposal));

        $this->assertSame(['agreed', 'In order.'], [DpcCommitteeDecision::query()->sole()->decision, DpcCommitteeDecision::query()->sole()->remarks]);
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status, 'Accepting moves nothing: the sitting goes ahead.');
        $this->assertTrue(app(CalculationGate::class)->chairmanAccepted($this->proposal->fresh()));

        // Once accepted, no second decision.
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'returned', 'remarks' => 'x'])->assertForbidden();
    }

    /**
     * Rejected by the DPC Chairman with a remark: to the Secretary, who marks the
     * candidates and sections; to the AH, who opens them; to the AO, who corrects only
     * them and sends it on to the AH — no OK of the earlier submission carried forward.
     */
    public function test_a_rejection_travels_secretary_ah_ao_and_back_to_the_ah(): void
    {
        $secretary = User::factory()->create();
        $secretary->assignRole(Role::Secretary->value);
        $this->makeChairman($this->member);
        DpcCandidateReview::query()->create(['dpc_proposal_id' => $this->proposal->id, 'dpc_evaluation_row_id' => $this->row->id, 'user_id' => $this->member->id, 'reviewed_at' => now()]);

        // A rejection needs its remark, and no section is chosen at the sitting.
        $this->actingAs($this->member)->post(route('sitting.decision', $this->proposal), ['decision' => 'returned'])->assertSessionHasErrors('remarks');
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'returned', 'remarks' => 'CR grades of 2023 do not match the copies.'])->assertRedirect();

        $proposal = $this->proposal->fresh();
        $this->assertSame([DpcProposalStatus::PendingSecretary, 'secretary'], [$proposal->status, $proposal->committeeReturnStage()]);
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'committee_reject')->count());

        // The Secretary marks; does not approve. Nobody else marks.
        $this->assertFalse($secretary->can('secretaryApprove', $proposal));
        $this->actingAs($this->ah)->get(route('dpc-proposals.committee-rejection', $proposal))->assertForbidden();
        $this->actingAs($secretary)->get(route('dpc-proposals.committee-rejection', $proposal))->assertOk()
            ->assertSee('CR grades of 2023 do not match the copies.')->assertSee('name="candidates['.$this->row->id.'][cr]"', false);
        $this->post(route('dpc-proposals.committee-rejection.mark', $proposal), ['candidates' => []])->assertSessionHasErrors('candidates');
        $this->post(route('dpc-proposals.committee-rejection.mark', $proposal), ['candidates' => [$this->row->id => ['cr' => 'Grade 2023 to be checked.', 'basic' => '']]])
            ->assertRedirect(route('dpc-proposals.show', $proposal));

        $proposal = $proposal->fresh();
        $this->assertSame([DpcProposalStatus::Submitted, 'ah'], [$proposal->status, $proposal->committeeReturnStage()]);
        $this->assertSame([$this->row->id => ['cr' => 'Grade 2023 to be checked.']], $proposal->committeeReturnCandidates());

        // The AH opens them for the AO; does not approve.
        $this->assertFalse($this->ah->can('approve', $proposal));
        $this->actingAs($this->ah)->get(route('dpc-proposals.show', $proposal))->assertOk()->assertSee('id="open-for-ao"', false)->assertSee('Grade 2023 to be checked.');
        Carbon::setTestNow(now()->addDays(3));
        $this->post(route('dpc-proposals.committee-rejection.open', $proposal))->assertRedirect(route('dpc-proposals.show', $proposal));

        $proposal = $proposal->fresh();
        $this->assertSame([DpcProposalStatus::Returned, 'ao'], [$proposal->status, $proposal->committeeReturnStage()]);
        // The AO's Workflow Timeline runs from the opening, not from the application.
        $this->assertSame(now()->toDateTimeString(), app(DpcStageService::class)->deadline($proposal)['entered_at']->toDateTimeString());

        // Only that candidate's CR is open to the AO.
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$proposal, $this->row]))->assertOk()->assertSee('id="committee-return-banner"', false);
        $this->assertTrue($this->ao->can('updateEmployeeSection', [$proposal, 'cr', $this->row]));
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$proposal, 'basic', $this->row]));
        $this->put(route('dpc-proposals.employees.basic', [$proposal, $this->row]), ['dob_order_number' => 'X'])->assertForbidden();

        // Corrected, on to the AH — not straight to the committee — and every OK to be given again.
        $this->post(route('dpc-proposals.committee-resubmit', $proposal))->assertRedirect(route('dpc-proposals.show', $proposal));
        $proposal = $proposal->fresh();
        $this->assertSame(DpcProposalStatus::Submitted, $proposal->status);
        $this->assertNull($proposal->committeeReturnStage());
        $this->assertSame(1, DpcCandidateReview::query()->where('invalidation_reason', 'resubmission')->count());
        $this->assertTrue($this->ah->can('approve', $proposal), 'The AH approves it on, to the Secretary and so to the committee.');
        Carbon::setTestNow();
    }
}
