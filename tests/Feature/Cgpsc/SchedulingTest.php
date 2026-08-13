<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
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
use RuntimeException;
use Tests\TestCase;

/**
 * Member acceptance and DPC scheduling (PPT S29–S30) — M6 Phase 2.
 */
class SchedulingTest extends TestCase
{
    use RefreshDatabase;

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

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->chairman = User::factory()->create(['department_id' => null, 'name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['department_id' => null, 'name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600001',
            'forwarded_at' => now(),
        ]);
    }

    private function allotTo(?User $member = null): DpcAllotment
    {
        return app(AllotmentService::class)->allot(
            $this->proposal->fresh(),
            $member ?? $this->memberOne,
            $this->chairman,
            request(),
            Carbon::parse('2026-07-10'),
        );
    }

    private function accept(?User $as = null)
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.schedules.accept', $this->proposal));
    }

    private function schedule(?User $as = null, string $date = '2026-07-12', string $time = '14:00')
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.schedules.store', $this->proposal), [
                'dpc_date' => $date,
                'dpc_time' => $time,
            ]);
    }

    // ------------------------------------------------------------- acceptance

    public function test_the_allottee_can_accept(): void
    {
        $this->allotTo();

        $this->accept()->assertRedirect(route('cgpsc.schedules.index'))->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->firstOrFail();
        $this->assertSame(AllotmentStatus::Accepted, $allotment->status);
        $this->assertNotNull($allotment->accepted_at);
    }

    public function test_a_member_cannot_accept_a_dpc_allotted_to_someone_else(): void
    {
        // Holding the Member role is not the same as being *this* DPC's member.
        $this->allotTo($this->memberOne);

        $this->accept($this->memberTwo)->assertForbidden();

        $this->assertSame(AllotmentStatus::Pending, DpcAllotment::query()->firstOrFail()->status);
    }

    public function test_a_dpc_cannot_be_accepted_twice(): void
    {
        $this->allotTo();
        $this->accept();

        $this->accept()->assertSessionHasErrors('scheduling');
    }

    public function test_accepting_an_unallotted_dpc_is_not_found(): void
    {
        $this->accept()->assertNotFound();
    }

    // -------------------------------------------------------------- scheduling

    public function test_the_allottee_can_schedule_after_accepting(): void
    {
        $this->allotTo();
        $this->accept();

        $this->schedule()->assertSessionHasNoErrors();

        $schedule = DpcSchedule::query()->firstOrFail();
        $this->assertSame('2026-07-12', $schedule->dpc_date->toDateString());
        $this->assertSame('14:00:00', $schedule->dpc_time);
        $this->assertSame($this->memberOne->id, $schedule->scheduled_by);
        $this->assertNull($schedule->superseded_by_id);

        $this->assertSame(AllotmentStatus::Scheduled, DpcAllotment::query()->firstOrFail()->status);
    }

    public function test_a_dpc_cannot_be_scheduled_before_it_is_accepted(): void
    {
        $this->allotTo();

        $this->schedule()->assertSessionHasErrors('scheduling');

        $this->assertSame(0, DpcSchedule::query()->count());
    }

    public function test_scheduling_is_not_validated_against_the_default_dpc_date(): void
    {
        // Architect Decision 4: the default is informational until CGPSC
        // specifies a rule. A date well before it must still be accepted —
        // enforcing otherwise would be inventing a business rule.
        $this->allotTo();
        $this->accept();

        $this->schedule(date: '2026-06-30')->assertSessionHasNoErrors();

        $this->assertSame('2026-06-30', DpcSchedule::query()->firstOrFail()->dpc_date->toDateString());
    }

    public function test_a_past_date_is_permitted(): void
    {
        // CGPSC may legitimately record a DPC that has already been held.
        $this->allotTo();
        $this->accept();

        $this->schedule(date: '2020-01-15')->assertSessionHasNoErrors();
    }

    public function test_the_time_must_be_well_formed(): void
    {
        $this->allotTo();
        $this->accept();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12',
            'dpc_time' => 'two o clock',
        ])->assertSessionHasErrors('dpc_time');
    }

    public function test_a_member_cannot_schedule_a_dpc_allotted_to_someone_else(): void
    {
        $this->allotTo($this->memberOne);
        $this->accept();

        $this->schedule($this->memberTwo)->assertForbidden();
    }

    // ------------------------------------------------------------ immutability

    public function test_a_schedule_cannot_be_edited(): void
    {
        $this->allotTo();
        $this->accept();
        $this->schedule();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be edited');

        DpcSchedule::query()->firstOrFail()->update(['dpc_date' => '2026-08-01']);
    }

    public function test_a_schedule_cannot_be_deleted(): void
    {
        $this->allotTo();
        $this->accept();
        $this->schedule();

        $this->expectException(RuntimeException::class);

        DpcSchedule::query()->firstOrFail()->delete();
    }

    // -------------------------------------------------- audit, timeline, state

    public function test_each_transition_writes_a_ledger_entry_and_a_timeline_entry(): void
    {
        $this->allotTo();
        $this->accept();
        $this->schedule();

        foreach (['allot', 'accept', 'schedule'] as $event) {
            $this->assertSame(1, WorkflowTransition::query()
                ->where('workflow', 'dpc_cgpsc')->where('event', $event)->count(), "ledger: {$event}");

            $this->assertTrue(ActivityTimeline::query()
                ->where('subject_id', $this->proposal->id)
                ->where('event', "dpc_cgpsc.{$event}")->exists(), "timeline: {$event}");
        }

        $this->assertGreaterThan(0, DpcSchedule::query()->firstOrFail()->audits()->count());
    }

    public function test_the_workflow_state_is_derived_never_stored_on_the_proposal(): void
    {
        $allotments = app(AllotmentService::class);

        $this->assertSame('unallotted', $allotments->state($this->proposal));

        $this->allotTo();
        $this->assertSame('allotted', $allotments->state($this->proposal->fresh()));

        $this->accept();
        $this->assertSame('accepted', $allotments->state($this->proposal->fresh()));

        $this->schedule();
        $this->assertSame('scheduled', $allotments->state($this->proposal->fresh()));

        // Nothing about CGPSC state leaked onto M4's frozen table.
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status);
    }

    public function test_scheduling_history_is_reproducible_from_timeline_alone(): void
    {
        $this->allotTo();
        $this->accept();
        $this->schedule();

        $entry = ActivityTimeline::query()
            ->where('subject_id', $this->proposal->id)
            ->where('event', 'dpc_cgpsc.schedule')
            ->firstOrFail();

        $p = $entry->properties;

        // Everything an export or a calendar subscriber needs, without
        // reading a business table.
        $this->assertSame('2026-07-12', $p['dpc_date']);
        $this->assertSame('14:00:00', $p['dpc_time']);
        $this->assertSame('Member One', $p['scheduled_by_name']);
        $this->assertSame('2026-07-10', $p['default_dpc_date']);
        $this->assertNotEmpty($p['schedule_uuid']);
        $this->assertNotEmpty($p['scheduled_for']);
    }

    // ------------------------------------------- directive: provenance stamps

    public function test_every_timeline_event_carries_a_uuid_and_schema_version(): void
    {
        $this->allotTo();
        $this->accept();

        foreach (ActivityTimeline::query()->get() as $entry) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $entry->uuid);
            $this->assertSame(1, $entry->schema_version);
        }
    }

    public function test_every_workflow_transition_carries_its_own_uuid(): void
    {
        $this->allotTo();
        $this->accept();

        $uuids = WorkflowTransition::query()->pluck('uuid');

        $this->assertCount(2, $uuids);
        $this->assertCount(2, $uuids->unique());
        foreach ($uuids as $uuid) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $uuid);
        }
    }

    public function test_a_failing_notification_never_fails_the_workflow(): void
    {
        // Architect directive: workflow completion must never fail because
        // notifications fail.
        $this->allotTo();

        Event::listen(WorkflowTransitioned::class, function (): void {
            throw new RuntimeException('SMS gateway exploded.');
        });

        $this->accept()->assertSessionHasNoErrors();

        $this->assertSame(AllotmentStatus::Accepted, DpcAllotment::query()->firstOrFail()->status);
    }

    // ------------------------------------------------------------ screens

    public function test_a_member_sees_only_their_own_allotments(): void
    {
        $this->allotTo($this->memberTwo);

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('No DPC has been allotted to you yet');

        $this->actingAs($this->memberTwo)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('HE202600001');
    }

    public function test_the_chairman_sees_every_allotment(): void
    {
        $this->allotTo($this->memberOne);

        $this->actingAs($this->chairman)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('HE202600001')
            ->assertSee('Member One');
    }

    public function test_the_scheduling_report_shows_date_and_time(): void
    {
        $this->allotTo();
        $this->accept();
        $this->schedule();

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('Scheduling Report')
            ->assertSee('DONE')
            ->assertSee('12/07/2026')
            ->assertSee('02:00 PM');
    }

    public function test_a_department_officer_cannot_reach_the_scheduling_area(): void
    {
        $ao = User::factory()->create(['department_id' => $this->department->id]);
        $ao->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($ao)->get(route('cgpsc.schedules.index'))->assertForbidden();
    }

    public function test_the_service_layer_refuses_a_non_allottee_even_bypassing_http(): void
    {
        $this->allotTo($this->memberOne);

        $this->expectException(WorkflowException::class);

        app(SchedulingService::class)->accept($this->proposal->fresh(), $this->memberTwo, request());
    }
}
