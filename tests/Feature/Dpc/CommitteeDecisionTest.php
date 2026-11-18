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
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Services\SittingMeetingService;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
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
    }

    public function test_a_member_records_agreement(): void
    {
        $this->actingAs($this->member)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertSee('id="committee-decision"', false)->assertSee('name="section_remarks[cr]"', false);

        // Every candidate is OK'd before Agree (2026-11-18).
        $this->post(route('sitting.candidate.ok', [$this->proposal, $this->row]));
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed', 'remarks' => 'Evaluation in order.'])
            ->assertRedirect(route('sitting.show', $this->proposal));

        $decision = DpcCommitteeDecision::query()->sole();
        $this->assertSame(['agreed', 'Evaluation in order.'], [$decision->decision, $decision->remarks]);
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status, 'Agreeing moves nothing.');
        $this->get(route('sitting.show', $this->proposal))->assertSee('data-committee-decision="agreed"', false);

        // Somebody without a seat cannot decide.
        $this->actingAs($this->ah)->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed'])->assertForbidden();
    }

    public function test_a_section_wise_return_opens_only_those_sections_and_goes_straight_back_to_the_committee(): void
    {
        // A ticked section needs its remark.
        $this->actingAs($this->member)->post(route('sitting.decision', $this->proposal), ['decision' => 'returned', 'sections' => ['cr']])
            ->assertSessionHasErrors('section_remarks.cr');
        // The member had OK'd the candidate on the submission being returned (2026-11-18).
        app(SittingMeetingService::class)->review($this->proposal, $this->row, $this->member);
        $this->post(route('sitting.decision', $this->proposal), ['decision' => 'returned'])->assertSessionHasErrors('decision');
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status);

        $this->post(route('sitting.decision', $this->proposal), [
            'decision' => 'returned', 'sections' => ['cr', 'property'],
            'section_remarks' => ['cr' => 'CR 2023 grade does not match the copy.', 'property' => 'Property File 2022 date is wrong.', 'basic' => 'ignored: not ticked'],
        ])->assertRedirect(route('sitting.show', $this->proposal));

        $proposal = $this->proposal->fresh();
        $this->assertSame(DpcProposalStatus::Returned, $proposal->status, 'Straight to the AO.');
        $this->assertSame(['cr', 'property'], $proposal->committeeReturnSections());
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'committee_return')->count());

        // The AO sees which sections, and only those can be changed.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))->assertOk()
            ->assertSee('id="committee-return"', false)->assertSee('CR 2023 grade does not match the copy.')
            ->assertSee('id="committee-resubmit"', false)->assertDontSee('id="dpc-submit"', false);
        $page = $this->get(route('dpc-proposals.employees.show', [$proposal, $this->row]))->assertOk()
            ->assertSee('id="committee-return-banner"', false);
        $this->assertStringContainsString('name="put_up"', (string) $page->getContent(), 'The CR form is open.');

        $this->put(route('dpc-proposals.employees.basic', [$proposal, $this->row]), [
            'dob_order_number' => 'X', 'dob_order_date' => '1995-07-01', 'joining_order_number' => 'Y', 'joining_order_date' => '1995-07-01', 'service_record_undertaking' => '1',
        ])->assertForbidden();
        $this->put(route('dpc-proposals.employees.vigilance', [$proposal, $this->row]), ['enquiry_ongoing' => '0', 'punishment_effective' => '0', 'vigilance_pending' => '0'])->assertForbidden();
        $year = (int) $this->row->crGrades()->value('year');
        $this->delete(route('dpc-proposals.employees.cr.remove', [$proposal, $this->row, $year]))->assertRedirect();
        $this->assertSame(0, $this->row->crGrades()->where('year', $year)->count(), 'A returned section is changed.');

        // The proposal's own screens and the ordinary submission to the AH are locked.
        $this->assertFalse($this->ao->can('update', $proposal));
        $this->assertFalse($this->ao->can('submit', $proposal));

        // Resubmitted: straight back to the committee — not to the AH.
        $this->post(route('dpc-proposals.committee-resubmit', $proposal))->assertRedirect(route('dpc-proposals.show', $proposal));
        $proposal = $proposal->fresh();
        $this->assertSame(DpcProposalStatus::Approved, $proposal->status);
        $this->assertNull($proposal->committeeReturnSections());
        $this->assertNotNull(DpcCommitteeDecision::query()->where('decision', 'returned')->sole()->resubmitted_at);
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'committee_resubmit')->count());
        $this->assertSame(0, WorkflowTransition::query()->where('to_state', 'submitted')->where('id', '>', WorkflowTransition::query()->where('event', 'committee_return')->value('id'))->count(), 'The AH does not see it again.');

        // No OK of the returned submission carries forward: the member OKs the corrected
        // record before agreeing again (2026-11-18).
        $this->assertSame(1, DpcCandidateReview::query()->where('invalidation_reason', 'resubmission')->count());
        $this->actingAs($this->member)->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed'])
            ->assertSessionHasErrors(['decision' => __('dpc.committee_decision.ok_first', ['count' => 1])]);
        $this->post(route('sitting.candidate.ok', [$this->proposal, $this->row]))->assertSessionHasNoErrors();

        // The member decides again on the corrected DPC.
        $this->actingAs($this->member)->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed'])->assertRedirect();
        $this->assertSame(2, DpcCommitteeDecision::query()->count());
    }
}
