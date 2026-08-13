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
use App\Domain\Master\Models\Cadre;
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
use Tests\TestCase;

/**
 * Re-scheduling, re-allotment history, and old-beside-new display
 * (PPT S30) — M6 Phase 3.
 */
class ReschedulingTest extends TestCase
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
