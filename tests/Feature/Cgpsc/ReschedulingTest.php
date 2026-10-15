<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * Re-scheduling, re-allotment history, and old-beside-new display
 * (PPT S30) — M6 Phase 3.
 */
class ReschedulingTest extends TestCase
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

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

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

        /*
         | A DPC is allotted to a serving member of **its own committee** since
         | 2026-09-06, and business corrected the qualification on 2026-09-07: it is
         | committee membership, not a CGPSC role. So the accounts these tests allot
         | to have to be on the committee.
         |
         | `seatCommittee()` seats the Commission's accounts on the member seats it
         | makes, which covers every allottee used here.
         */
        $this->seatCommittee($this->proposal);
    }

    private function scheduleIt(): void
    {
        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(),
            Carbon::parse('2026-07-10'),
        );
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal));
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12', 'dpc_time' => '14:00',
        ]);
    }

    private function reschedule(?User $as = null, string $date = '2026-07-15', string $time = '14:00', string $reason = 'Venue clash.')
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.schedules.reschedule', $this->proposal), [
                'dpc_date' => $date, 'dpc_time' => $time, 'reschedule_reason' => $reason,
            ]);
    }

    // ----------------------------------------------------------- rescheduling

    public function test_rescheduling_creates_a_new_record_and_supersedes_the_old(): void
    {
        // S30's worked example: 12/07/2026 2:00 PM moves to 15/07/2026 2:00 PM.
        $this->scheduleIt();
        $first = DpcSchedule::query()->firstOrFail();

        $this->reschedule()->assertSessionHasNoErrors();

        $this->assertSame(2, DpcSchedule::query()->count());

        $first->refresh();
        $second = DpcSchedule::query()->latest('id')->firstOrFail();

        // The old row survives untouched apart from being superseded.
        $this->assertSame($second->id, $first->superseded_by_id);
        $this->assertSame('2026-07-12', $first->dpc_date->toDateString());
        $this->assertSame('14:00:00', $first->dpc_time);

        $this->assertSame('2026-07-15', $second->dpc_date->toDateString());
        $this->assertSame('Venue clash.', $second->reschedule_reason);
        $this->assertNull($second->superseded_by_id);
    }

    public function test_rescheduling_requires_a_reason(): void
    {
        $this->scheduleIt();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-07-15', 'dpc_time' => '14:00',
        ])->assertSessionHasErrors('reschedule_reason');

        $this->assertSame(1, DpcSchedule::query()->count());
    }

    public function test_rescheduling_to_the_same_slot_is_refused(): void
    {
        $this->scheduleIt();

        $this->reschedule(date: '2026-07-12', time: '14:00')->assertSessionHasErrors('scheduling');

        $this->assertSame(1, DpcSchedule::query()->count());
    }

    public function test_the_same_date_at_a_different_time_is_a_valid_reschedule(): void
    {
        $this->scheduleIt();

        $this->reschedule(date: '2026-07-12', time: '16:30')->assertSessionHasNoErrors();

        $this->assertSame('16:30:00', DpcSchedule::query()->latest('id')->firstOrFail()->dpc_time);
    }

    public function test_an_unscheduled_dpc_cannot_be_rescheduled(): void
    {
        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(),
        );
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal));

        $this->reschedule()->assertSessionHasErrors('scheduling');
    }

    public function test_a_member_cannot_reschedule_another_members_dpc(): void
    {
        $this->scheduleIt();

        $this->reschedule($this->memberTwo)->assertForbidden();
    }

    public function test_rescheduling_can_repeat_and_every_step_is_kept(): void
    {
        $this->scheduleIt();
        $this->reschedule(date: '2026-07-15');
        $this->reschedule(date: '2026-07-20', reason: 'Member unavailable.');

        $this->assertSame(3, DpcSchedule::query()->count());
        $this->assertSame(1, DpcSchedule::query()->whereNull('superseded_by_id')->count());
        $this->assertSame('2026-07-20', app(SchedulingService::class)->current($this->proposal)->dpc_date->toDateString());
    }

    public function test_a_superseded_schedule_still_cannot_be_edited(): void
    {
        $this->scheduleIt();
        $this->reschedule();

        $this->expectException(RuntimeException::class);

        DpcSchedule::query()->whereNotNull('superseded_by_id')->firstOrFail()->update(['dpc_date' => '2030-01-01']);
    }

    // ------------------------------------------------- old vs new on screen

    /**
     * The allottee can actually *reach* rescheduling from the screen.
     *
     * **Nineteen tests exercised the reschedule endpoint and none of them looked at
     * the screen**, so the control could disappear from the page with the whole
     * suite green — the POST kept working for anybody who knew the URL, which is
     * nobody. The affordance is the feature here, not the route.
     */
    // ------------------------------------------- the allottee who holds no role

    /**
     * A DPC allotted to a committee member who holds no portal role is still
     * theirs to accept, schedule and reschedule.
     *
     * **This is the case that dead-ended.** Since 2026-09-07 the qualification for
     * being allotted a DPC is membership of that DPC's committee and explicitly not
     * a role — *"sitting on one DPC's committee is not an office"* — so the Chairman
     * may allot to somebody with no role at all. `DpcAllotmentPolicy::viewAny()`
     * still asked for `Chairman` or `Member`, so the allotment succeeded and the
     * allottee was then refused the only screen that can move the DPC: no accept, no
     * decline, no schedule, no reschedule. It did not return to the Chairman either,
     * because nothing had lapsed. The DPC simply stopped.
     */
    public function test_an_allottee_who_holds_no_portal_role_can_reach_the_screen_and_accept(): void
    {
        $seated = User::factory()->create(['name' => 'Deputy Secretary, GAD']);
        $this->seatAccountOnCommittee($this->proposal, $seated);

        $this->assertSame([], $seated->roles->pluck('name')->all(), 'The point of this test is that they hold none.');

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $seated, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );

        $this->actingAs($seated)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee(route('cgpsc.schedules.accept', $this->proposal), escape: false);

        $this->actingAs($seated)->post(route('cgpsc.schedules.accept', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->actingAs($seated)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12', 'dpc_time' => '14:00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($seated)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee(route('cgpsc.schedules.reschedule', $this->proposal), escape: false);
    }

    public function test_an_allottee_who_holds_no_portal_role_is_offered_the_screen_from_the_dashboard(): void
    {
        $seated = User::factory()->create(['name' => 'Deputy Secretary, GAD']);
        $this->seatAccountOnCommittee($this->proposal, $seated);

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $seated, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );

        $this->actingAs($seated)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('cgpsc.schedules.index'), escape: false);
    }

    /**
     * And nothing is widened: holding no role and no allotment still opens nothing.
     */
    public function test_somebody_with_neither_a_role_nor_an_allotment_cannot_open_the_screen(): void
    {
        $stranger = User::factory()->create(['name' => 'Nobody In Particular']);

        $this->actingAs($stranger)->get(route('cgpsc.schedules.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('cgpsc.schedules.index'), escape: false);
    }

    /**
     * An allottee with no role sees their own DPC and no other.
     */
    public function test_a_role_less_allottee_sees_only_their_own_dpc(): void
    {
        $seated = User::factory()->create(['name' => 'Deputy Secretary, GAD']);
        $this->seatAccountOnCommittee($this->proposal, $seated);

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $seated, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );

        $other = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'promotion_to_post_id' => $this->proposal->promotion_to_post_id,
            'promotion_from_post_id' => $this->proposal->promotion_from_post_id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'DPC-2026-HE-0002',
            'forwarded_at' => now(),
        ]);
        $this->seatCommittee($other);
        app(AllotmentService::class)->allot(
            $other->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );

        $this->actingAs($seated)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('DPC-2026-HE-0001')
            ->assertDontSee('DPC-2026-HE-0002');
    }

    public function test_the_allottee_sees_the_reschedule_control_on_the_schedule_screen(): void
    {
        $this->scheduleIt();

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee(route('cgpsc.schedules.reschedule', $this->proposal), escape: false);
    }

    public function test_the_reschedule_control_survives_a_reschedule(): void
    {
        $this->scheduleIt();
        $this->reschedule()->assertSessionHasNoErrors();

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee(route('cgpsc.schedules.reschedule', $this->proposal), escape: false);
    }

    public function test_a_member_who_is_not_the_allottee_sees_no_reschedule_control(): void
    {
        $this->scheduleIt();

        $this->actingAs($this->memberTwo)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertDontSee(route('cgpsc.schedules.reschedule', $this->proposal), escape: false);
    }

    public function test_the_schedule_screen_shows_the_previous_slot_beside_the_new(): void
    {
        $this->scheduleIt();
        $this->reschedule();

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.index'))
            ->assertOk()
            ->assertSee('15/07/2026')
            ->assertSee('12/07/2026')   // the superseded date, struck through
            ->assertSee('<s>', false);
    }

    public function test_the_allotment_screen_shows_the_previous_allottee_beside_the_new(): void
    {
        app(AllotmentService::class)->allot($this->proposal->fresh(), $this->memberOne, $this->chairman, request());
        app(AllotmentService::class)->reallot(
            $this->proposal->fresh(), $this->memberTwo, $this->chairman, request(), 'Member One is on leave.',
        );

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))
            ->assertOk()
            ->assertSee('Member Two')
            ->assertSee('Member One')
            ->assertSee('<s>', false);
    }

    public function test_the_scheduling_history_screen_renders_from_the_timeline(): void
    {
        $this->scheduleIt();
        $this->reschedule();

        $this->actingAs($this->memberOne)->get(route('cgpsc.schedules.history', $this->proposal))
            ->assertOk()
            ->assertSee('Re-scheduled')
            ->assertSee('Venue clash.')
            ->assertSee('15/07/2026')
            ->assertSee('12/07/2026');
    }

    // ------------------------------------------------------------- timeline

    public function test_the_reschedule_timeline_entry_carries_both_slots(): void
    {
        $this->scheduleIt();
        $this->reschedule();

        $entry = ActivityTimeline::query()->where('event', 'dpc_cgpsc.reschedule')->firstOrFail();
        $p = $entry->properties;

        $this->assertSame('2026-07-15', $p['dpc_date']);
        $this->assertSame('2026-07-12', $p['previous_dpc_date']);
        $this->assertSame('14:00:00', $p['previous_dpc_time']);
        $this->assertSame('Venue clash.', $p['remarks']);
        $this->assertNotEmpty($p['superseded_schedule_uuid']);
    }

    // ---------------------------------------- directives: actor + versioning

    public function test_every_timeline_event_snapshots_the_actor(): void
    {
        // Architect directive: actor UUID and display-name snapshot on every
        // event, so a renamed or deleted user cannot alter what the record says.
        $this->scheduleIt();

        $entry = ActivityTimeline::query()->where('event', 'dpc_cgpsc.schedule')->firstOrFail();

        $this->assertSame($this->memberOne->uuid, $entry->actor_uuid);
        $this->assertSame('Member One', $entry->actor_name);
    }

    public function test_renaming_the_actor_does_not_change_the_record(): void
    {
        $this->scheduleIt();

        $this->memberOne->forceFill(['name' => 'Someone Else Entirely'])->save();

        $entry = ActivityTimeline::query()->where('event', 'dpc_cgpsc.schedule')->firstOrFail();

        $this->assertSame('Member One', $entry->actor_name, 'The snapshot must record who acted, not who they became.');
    }

    public function test_the_notification_payload_carries_its_version(): void
    {
        $this->scheduleIt();

        Event::fake([WorkflowTransitioned::class]);
        $this->reschedule();

        Event::assertDispatched(
            WorkflowTransitioned::class,
            fn (WorkflowTransitioned $event): bool => $event->payloadVersion === 1,
        );
    }

    public function test_scheduling_records_the_time_zone_it_was_entered_in(): void
    {
        // Preparation for future time-zone support: a stored 14:00 is
        // meaningless without the zone it was meant in.
        $this->scheduleIt();

        $entry = ActivityTimeline::query()->where('event', 'dpc_cgpsc.schedule')->firstOrFail();

        $this->assertSame('Asia/Kolkata', $entry->properties['timezone']);
    }

    // -------------------------------------------------------------- replay

    public function test_the_full_workflow_can_be_replayed_from_the_timeline_alone(): void
    {
        // Architect directive: a future integration must be able to regenerate
        // notifications and calendar events from the timeline only. This
        // rebuilds both without touching a business table.
        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal));
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12', 'dpc_time' => '14:00',
        ]);
        $this->reschedule();

        $events = ActivityTimeline::query()
            ->where('subject_id', $this->proposal->id)
            ->where('event', 'like', 'dpc_cgpsc.%')
            ->orderBy('occurred_at')
            ->get();

        $this->assertSame(
            ['dpc_cgpsc.allot', 'dpc_cgpsc.accept', 'dpc_cgpsc.schedule', 'dpc_cgpsc.reschedule'],
            $events->pluck('event')->all(),
        );

        // A notification could be regenerated for every event.
        foreach ($events as $event) {
            $this->assertNotEmpty($event->actor_name);
            $this->assertNotEmpty($event->uuid);
            $this->assertSame(1, $event->schema_version);
            $this->assertNotEmpty($event->description);
        }

        // A calendar entry could be regenerated from the latest schedule event.
        $latest = $events->last()->properties;
        $this->assertNotEmpty($latest['scheduled_for']);
        $this->assertSame('Asia/Kolkata', $latest['timezone']);
        $this->assertNotEmpty($latest['schedule_uuid']);
        $this->assertSame('2026-07-15', $latest['dpc_date']);
    }

    public function test_the_service_layer_refuses_a_non_allottee_reschedule_bypassing_http(): void
    {
        $this->scheduleIt();

        $this->expectException(WorkflowException::class);

        app(SchedulingService::class)->reschedule(
            $this->proposal->fresh(), $this->memberTwo, request(),
            Carbon::parse('2026-07-20'), '10:00', 'Not mine to move.',
        );
    }

    public function test_allotment_history_survives_rescheduling(): void
    {
        // Two independent histories on the same proposal must not interfere.
        app(AllotmentService::class)->allot($this->proposal->fresh(), $this->memberOne, $this->chairman, request());
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal));
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12', 'dpc_time' => '14:00',
        ]);
        $this->reschedule();

        $allotmentEvents = app(AllotmentService::class)->historyFromTimeline($this->proposal);
        $scheduleEvents = app(SchedulingService::class)->historyFromTimeline($this->proposal);

        $this->assertCount(1, $allotmentEvents);
        $this->assertCount(3, $scheduleEvents);
        $this->assertSame(1, DpcAllotment::query()->count());
    }
}
