<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * The DPC sitting — opening, access, evidence and history (PPT S31–S34) —
 * M7 Phase 1.
 */
class ProceedingLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private Department $department;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $postFrom = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'promotion_to_post_id' => $postTo->id,
            'promotion_from_post_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'DPC-2026-HE-0001',
            'forwarded_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- helpers

    private function allotTo(?User $member = null): DpcAllotment
    {
        /*
         | Seated before allotting: a DPC is allotted to a serving member of its own
         | committee (business, 2026-09-06), and the qualification is committee
         | membership rather than a Commission role (correction, 2026-09-07).
         */
        $this->seatCommittee($this->proposal);

        return app(AllotmentService::class)->allot(
            $this->proposal->fresh(),
            $member ?? $this->memberOne,
            $this->chairman,
            request(),
            Carbon::parse('2026-07-10'),
        );
    }

    private function scheduleFor(?User $member = null): void
    {
        $member ??= $this->memberOne;
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($this->proposal->fresh(), $member, request());
        $scheduling->schedule($this->proposal->fresh(), $member, request(), Carbon::parse('2026-07-12'), '14:00');
    }

    private function allottedAndScheduled(?User $member = null): void
    {
        $this->allotTo($member);
        $this->scheduleFor($member);
    }

    private function open(?User $as = null)
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.proceedings.open', $this->proposal));
    }

    private function addEvaluationRow(int $sn, string $name): void
    {
        $category = ReservationCategory::query()->firstOrFail();

        $row = $this->proposal->evaluationRows()->create([
            'sn' => $sn,
            'name' => $name,
            'dob' => '1970-01-01',
            'reservation_category_id' => $category->id,
            'tribal_area_service_years' => null,
        ]);

        $row->crGrades()->create(['year' => 2017, 'grade' => 'क']);
        $row->loyaltyFlags()->create(['year' => 2017, 'flag' => true]);
        $row->propertyFlags()->create(['year' => 2017, 'flag' => true]);
    }

    // -------------------------------------------------------------- opening

    public function test_the_allottee_can_open_the_proceeding(): void
    {
        $this->allottedAndScheduled();

        $this->open()->assertSessionHasNoErrors();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $this->assertSame(DpcProceedingStatus::InProceeding, $proceeding->status);
        $this->assertSame($this->memberOne->id, $proceeding->conducted_by);
        $this->assertNotNull($proceeding->opened_at);
    }

    public function test_a_member_cannot_open_a_dpc_allotted_to_someone_else(): void
    {
        // Holding the Member role is not the same as being *this* DPC's member.
        $this->allottedAndScheduled($this->memberOne);

        // A proper 403 rather than a validation message — the allottee check
        // is a permission question, not a form question.
        $this->open($this->memberTwo)->assertForbidden();

        $this->assertSame(0, DpcProceeding::query()->count());
    }

    public function test_the_chairman_cannot_conduct_a_dpc_allotted_to_a_member(): void
    {
        // AQ-6: reading is not conducting. The Chairman re-allots to himself
        // first, so exactly one actor is accountable for the sitting.
        $this->allottedAndScheduled($this->memberOne);

        $this->open($this->chairman)->assertForbidden();

        $this->assertSame(0, DpcProceeding::query()->count());
    }

    public function test_a_dpc_cannot_be_opened_before_it_is_scheduled(): void
    {
        $this->allotTo();

        $this->open()->assertSessionHasErrors('proceeding');

        $this->assertSame(0, DpcProceeding::query()->count());
    }

    public function test_an_unapproved_proposal_cannot_be_taken_up(): void
    {
        $this->allottedAndScheduled();

        // A DPC reaches the Commission only by being approved and forwarded.
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value])->saveQuietly();

        $this->open()->assertSessionHasErrors('proceeding');

        $this->assertSame(0, DpcProceeding::query()->count());
    }

    public function test_a_proceeding_cannot_be_opened_twice(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->open()->assertSessionHasErrors('proceeding');

        $this->assertSame(1, DpcProceeding::query()->count());
    }

    public function test_a_concluded_dpc_cannot_be_reopened(): void
    {
        // Correction after conclusion is a Review DPC, never a re-opening.
        $this->allottedAndScheduled();
        $this->open();

        DpcProceeding::query()->firstOrFail()
            ->update(['status' => DpcProceedingStatus::ResultApproved->value]);

        $this->open()->assertSessionHasErrors('proceeding');

        $this->assertSame(1, DpcProceeding::query()->count());
    }

    // ------------------------------------------------------------ early open

    public function test_opening_early_is_permitted_by_default(): void
    {
        $this->allottedAndScheduled();
        Carbon::setTestNow('2026-07-01 09:00:00');   // before the 12/07 slot

        $this->open()->assertSessionHasNoErrors();

        $this->assertSame(1, DpcProceeding::query()->count());
        Carbon::setTestNow();
    }

    public function test_opening_early_is_refused_when_configuration_forbids_it(): void
    {
        config()->set('dpc.proceeding.allow_open_before_scheduled_time', false);

        $this->allottedAndScheduled();
        Carbon::setTestNow('2026-07-01 09:00:00');

        $this->open()->assertSessionHasErrors('proceeding');

        $this->assertSame(0, DpcProceeding::query()->count());
        Carbon::setTestNow();
    }

    public function test_the_actual_opening_time_is_always_recorded(): void
    {
        // AQ-5: whether early opening is allowed is configurable; recording
        // when it actually happened is not.
        $this->allottedAndScheduled();
        Carbon::setTestNow('2026-07-01 09:30:00');

        $this->open();

        $this->assertSame(
            '2026-07-01 09:30:00',
            DpcProceeding::query()->firstOrFail()->opened_at->format('Y-m-d H:i:s')
        );
        Carbon::setTestNow();
    }

    // -------------------------------------------------------------- evidence

    public function test_the_department_submission_fingerprint_is_recorded_at_opening(): void
    {
        // S31: department entries are fixed once the proposal is forwarded.
        // The fingerprint is what makes that provable rather than arguable.
        $this->addEvaluationRow(1, 'US1');
        $this->allottedAndScheduled();

        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $this->assertSame(64, strlen($proceeding->proposal_version));
        $this->assertSame(
            app(ProceedingService::class)->chartFingerprint($this->proposal->fresh()),
            $proceeding->proposal_version,
        );
    }

    public function test_the_fingerprint_changes_when_the_chart_changes(): void
    {
        $this->addEvaluationRow(1, 'US1');
        $service = app(ProceedingService::class);
        $before = $service->chartFingerprint($this->proposal->fresh());

        $this->addEvaluationRow(2, 'US2');

        $this->assertNotSame($before, $service->chartFingerprint($this->proposal->fresh()));
    }

    public function test_the_fingerprint_covers_immovable_property_returns(): void
    {
        // Deliberately wider than M5's scoring fingerprint: a missing property
        // return changes nothing about a score but decides whether an officer
        // is held, and S31's claim is about the whole submission.
        $this->addEvaluationRow(1, 'US1');
        $service = app(ProceedingService::class);
        $before = $service->chartFingerprint($this->proposal->fresh());

        $this->proposal->fresh()->evaluationRows->first()
            ->propertyFlags()->where('year', 2017)->update(['flag' => false]);

        $this->assertNotSame($before, $service->chartFingerprint($this->proposal->fresh()));
    }

    public function test_the_conducting_member_name_is_snapshotted(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->memberOne->update(['name' => 'Renamed Member']);

        // Renaming a user must not change who conducted the sitting.
        $this->assertSame('Member One', DpcProceeding::query()->firstOrFail()->conducted_by_name);
    }

    // ---------------------------------------------------- workflow & timeline

    public function test_opening_records_a_workflow_transition(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $transition = WorkflowTransition::query()
            ->where('workflow', 'dpc_proceeding')->where('event', 'open')->firstOrFail();

        $this->assertSame('scheduled', $transition->from_state);
        $this->assertSame('in_proceeding', $transition->to_state);
        $this->assertSame($this->memberOne->id, $transition->actor_id);
    }

    public function test_opening_records_a_replayable_timeline_entry(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.open')->firstOrFail();
        $properties = $entry->properties;
        $proceeding = DpcProceeding::query()->firstOrFail();

        // Everything a replaying subscriber needs, without reading a business
        // table (architect Directive 5).
        $this->assertSame($proceeding->uuid, $properties['proceeding_uuid']);
        $this->assertSame('2026-07-12', $properties['dpc_date']);
        $this->assertSame('Member One', $properties['conducted_by_name']);
        $this->assertSame($proceeding->proposal_version, $properties['proposal_version']);
        $this->assertNotEmpty($properties['scheduled_for']);
        $this->assertSame('Member One', $entry->actor_name);
        $this->assertNotNull($entry->actor_uuid);
    }

    public function test_history_is_read_from_the_timeline_alone(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $entries = app(ProceedingService::class)->historyFromTimeline($this->proposal->fresh());

        $this->assertCount(1, $entries);
        $this->assertSame('dpc_proceeding.open', $entries->first()->event);
    }

    public function test_the_workflow_state_is_derived_not_stored(): void
    {
        $this->allottedAndScheduled();
        $service = app(ProceedingService::class);

        $this->assertSame('scheduled', $service->state($this->proposal->fresh()));

        $this->open();

        $this->assertSame('in_proceeding', $service->state($this->proposal->fresh()));
        // M4 is frozen — the proposal's own status never moved.
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status);
    }

    public function test_opening_notifies_the_department(): void
    {
        Event::fake([WorkflowTransitioned::class]);

        $this->allottedAndScheduled();
        $this->open();

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event): bool {
            return $event->event === 'open'
                && in_array('authorized_officer', $event->notifyRoles, true)
                && in_array('administrative_head', $event->notifyRoles, true);
        });
    }

    public function test_opening_is_audited(): void
    {
        // Auditing is asserted, never assumed: `audit.console` was hardcoded
        // off for five milestones and silently disabled every audit assertion.
        $this->allottedAndScheduled();
        $this->open();

        $this->assertSame(
            1,
            Audit::query()->where('auditable_type', (new DpcProceeding)->getMorphClass())->count(),
        );
    }

    // ------------------------------------------------------- opened / closed

    public function test_a_proceeding_records_opened_by_and_opened_at(): void
    {
        // Architect Directive 1. Under AQ-6 the member who opens the sitting
        // is the member who conducts it, so `conducted_by` is the Opened By —
        // one fact with one column rather than two that must agree.
        Carbon::setTestNow('2026-07-12 14:05:00');
        $this->allottedAndScheduled();
        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $this->assertSame($this->memberOne->id, $proceeding->conducted_by);
        $this->assertSame('Member One', $proceeding->conducted_by_name);
        $this->assertSame('2026-07-12 14:05:00', $proceeding->opened_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_closure_identity_is_empty_until_the_sitting_is_closed(): void
    {
        // Directive 1's second pair. Phase 3 populates it when the sitting is
        // approved or cancelled; an open sitting must not claim a closer.
        $this->allottedAndScheduled();
        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $this->assertNull($proceeding->closed_by);
        $this->assertNull($proceeding->closed_by_name);
        $this->assertNull($proceeding->closed_at);
    }

    public function test_closure_identity_can_be_recorded_while_the_sitting_is_open(): void
    {
        // The columns must be writable in the same transaction that concludes
        // the sitting, or Phase 3 could never fill them — the terminal guard
        // rejects every write once the status has moved.
        $this->allottedAndScheduled();
        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $proceeding->update([
            'closed_by' => $this->memberOne->id,
            'closed_by_name' => $this->memberOne->name,
            'closed_at' => now(),
            'status' => DpcProceedingStatus::ResultApproved->value,
        ]);

        $fresh = $proceeding->fresh();
        $this->assertSame('Member One', $fresh->closed_by_name);
        $this->assertNotNull($fresh->closed_at);
    }

    // ------------------------------------------------------------ addressing

    public function test_a_proceeding_is_addressed_by_uuid_never_by_id(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();

        $this->assertSame('uuid', $proceeding->getRouteKeyName());
        $this->assertStringContainsString($proceeding->uuid, route('cgpsc.proceedings.show', $proceeding));
        $this->assertStringNotContainsString(
            '/proceedings/'.$proceeding->id,
            route('cgpsc.proceedings.show', $proceeding),
        );
    }

    /**
     * The allottee who holds no portal role conducts the sitting and sees the
     * candidates on it.
     *
     * **This is the far end of the same defect.** Since 2026-09-07 a DPC is allotted
     * to a member of its own committee and the allottee need hold no role, but
     * `DpcProceedingPolicy::viewAny()` and `::open()` still asked for one — so an
     * allottee could be admitted to Schedules, accept, fix a date, and then be
     * refused the screen that runs the sitting they had just scheduled.
     *
     * `view()` and `act()` needed no change: both already bind the individual by
     * `conducted_by`, and name no role.
     */
    public function test_an_allottee_with_no_portal_role_opens_the_sitting_and_sees_the_candidates(): void
    {
        $seated = User::factory()->create(['name' => 'Deputy Secretary, GAD']);

        $this->assertSame([], $seated->roles->pluck('name')->all(), 'The point of this test is that they hold none.');

        // Committee membership is the qualification, so it has to exist first.
        $this->seatAccountOnCommittee($this->proposal, $seated);

        $this->allottedAndScheduled($seated);
        $this->addEvaluationRow(1, 'Shri Ramesh Chandra Verma');

        $this->open($seated)->assertSessionHasNoErrors();

        $proceeding = DpcProceeding::query()->firstOrFail();

        $this->assertSame($seated->id, $proceeding->conducted_by, 'The allottee conducts it.');

        $this->actingAs($seated)->get(route('cgpsc.proceedings.index'))->assertOk();

        $this->actingAs($seated)->get(route('cgpsc.proceedings.show', $proceeding))
            ->assertOk()
            ->assertSee('Shri Ramesh Chandra Verma');
    }

    /**
     * And the sitting they may reach is their own.
     */
    public function test_a_role_less_allottee_cannot_read_another_conductors_sitting(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $stranger = User::factory()->create(['name' => 'Somebody Else']);

        $this->actingAs($stranger)
            ->get(route('cgpsc.proceedings.show', DpcProceeding::query()->firstOrFail()))
            ->assertForbidden();
    }

    public function test_another_member_cannot_view_the_proceeding(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->actingAs($this->memberTwo)
            ->get(route('cgpsc.proceedings.show', DpcProceeding::query()->firstOrFail()))
            ->assertForbidden();
    }

    public function test_the_chairman_may_read_a_proceeding_he_is_not_conducting(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->actingAs($this->chairman)
            ->get(route('cgpsc.proceedings.show', DpcProceeding::query()->firstOrFail()))
            ->assertOk();
    }

    public function test_the_workspace_renders_the_department_chart(): void
    {
        $this->addEvaluationRow(1, 'US1');
        $this->allottedAndScheduled();
        $this->open();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.show', DpcProceeding::query()->firstOrFail()))
            ->assertOk()
            ->assertSee('US1')
            ->assertSee('Evaluation Sheet');
    }

    public function test_the_history_screen_renders(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.history', DpcProceeding::query()->firstOrFail()))
            ->assertOk()
            ->assertSee('Proceeding History');
    }

    public function test_the_index_lists_only_the_members_own_dpcs(): void
    {
        $this->allottedAndScheduled($this->memberOne);

        $this->actingAs($this->memberTwo)
            ->get(route('cgpsc.proceedings.index'))
            ->assertOk()
            ->assertSee('No DPCs are awaiting a proceeding');
    }

    // ------------------------------------------------------------ immutability

    public function test_a_proceeding_cannot_be_deleted(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->expectException(RuntimeException::class);
        DpcProceeding::query()->firstOrFail()->delete();
    }

    public function test_the_evidence_columns_of_a_proceeding_cannot_be_edited(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $this->expectException(RuntimeException::class);
        DpcProceeding::query()->firstOrFail()->update(['conducted_by_name' => 'Someone Else']);
    }

    public function test_a_concluded_proceeding_is_frozen_entirely(): void
    {
        $this->allottedAndScheduled();
        $this->open();

        $proceeding = DpcProceeding::query()->firstOrFail();
        $proceeding->update(['status' => DpcProceedingStatus::ResultApproved->value]);

        // Even a permitted column is refused once the record is terminal.
        $this->expectException(RuntimeException::class);
        $proceeding->fresh()->update(['promotion_as_on_year' => 2017]);
    }

    public function test_the_service_refuses_to_open_when_the_dpc_is_unallotted(): void
    {
        $this->expectException(WorkflowException::class);

        app(ProceedingService::class)->open($this->proposal->fresh(), $this->memberOne, request());
    }
}
