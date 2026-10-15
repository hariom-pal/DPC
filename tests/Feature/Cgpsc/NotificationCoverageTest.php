<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Listeners\SendWorkflowNotification;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Feature;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * Notification coverage across every CGPSC workflow transition (architect
 * Decision 5) — M6 Phase 4.
 *
 * Earlier phases asserted the *event* carried the right recipients. These
 * tests assert the **listener actually resolves them to real users**, which is
 * a different claim: the department-scoped role query and the explicit
 * user-id list are two independent code paths, and only one of them can reach
 * a CGPSC Member.
 */
class NotificationCoverageTest extends TestCase
{
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private Department $department;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman', 'mobile' => '9000000001']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One', 'mobile' => '9000000002']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two', 'mobile' => '9000000003']);
        $this->memberTwo->assignRole(Role::Member->value);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create(['name' => 'Officer', 'mobile' => '9000000004']);

        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create(['name' => 'Head', 'mobile' => '9000000005']);

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

        Mail::fake();

        // Record who the application actually tries to message, so these
        // tests assert delivery rather than only event payloads.
        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);

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

    /**
     * Runs the real listener against a real event, then reports the mobiles it
     * tried to reach.
     *
     * @return list<string>
     */
    private function notifiedBy(WorkflowTransitioned $event): array
    {
        RecordingSmsSender::reset();
        app(SendWorkflowNotification::class)->handle($event);

        return RecordingSmsSender::mobiles();
    }

    /**
     * @return array<string, WorkflowTransitioned>
     */
    private function runFullWorkflow(): array
    {
        $captured = [];

        $this->app['events']->listen(WorkflowTransitioned::class, function (WorkflowTransitioned $e) use (&$captured): void {
            $captured[$e->event] = $e;
        });

        $allotments = app(AllotmentService::class);
        $allotments->allot($this->proposal->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'));
        $allotments->reallot($this->proposal->fresh(), $this->memberTwo, $this->chairman, request(), 'Member One is on leave.');

        $this->actingAs($this->memberTwo)->post(route('cgpsc.schedules.accept', $this->proposal));
        $this->actingAs($this->memberTwo)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-07-12', 'dpc_time' => '14:00',
        ]);
        $this->actingAs($this->memberTwo)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-07-15', 'dpc_time' => '14:00', 'reschedule_reason' => 'Venue clash.',
        ]);

        return $captured;
    }

    public function test_every_cgpsc_transition_publishes_a_notification_event(): void
    {
        $captured = $this->runFullWorkflow();

        $this->assertSame(
            ['allot', 'reallot', 'accept', 'schedule', 'reschedule'],
            array_keys($captured),
            'Every S26–S30 transition must publish an event.',
        );
    }

    public function test_every_transition_reaches_the_officer_the_head_and_the_allotted_member(): void
    {
        // Architect Decision 5, verified at the listener rather than the event.
        $captured = $this->runFullWorkflow();

        $expected = [
            'allot' => $this->memberOne,
            'reallot' => $this->memberTwo,
            'accept' => $this->memberTwo,
            'schedule' => $this->memberTwo,
            'reschedule' => $this->memberTwo,
        ];

        foreach ($expected as $event => $member) {
            $notified = $this->notifiedBy($captured[$event]);

            $this->assertContains($this->ao->mobile, $notified, "AO not notified on {$event}");
            $this->assertContains($this->ah->mobile, $notified, "AH not notified on {$event}");
            $this->assertContains($member->mobile, $notified, "Allotted member not notified on {$event}");
        }
    }

    public function test_re_allotment_also_reaches_the_outgoing_member(): void
    {
        $captured = $this->runFullWorkflow();

        $notified = $this->notifiedBy($captured['reallot']);

        $this->assertContains($this->memberTwo->mobile, $notified, 'incoming member');
        $this->assertContains($this->memberOne->mobile, $notified, 'outgoing member');
    }

    public function test_an_unrelated_department_is_never_notified(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $outsider = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create(['mobile' => '9111111111']);

        $captured = $this->runFullWorkflow();

        foreach ($captured as $event) {
            $this->assertNotContains($outsider->mobile, $this->notifiedBy($event));
        }
    }

    public function test_a_deactivated_user_is_not_notified(): void
    {
        $this->ah->forceFill(['is_active' => false])->save();

        $captured = $this->runFullWorkflow();

        $this->assertNotContains($this->ah->mobile, $this->notifiedBy($captured['allot']));
    }

    public function test_a_member_who_is_not_the_allottee_is_not_notified(): void
    {
        $captured = $this->runFullWorkflow();

        // memberOne is no longer the allottee after the re-allotment.
        $this->assertNotContains($this->memberOne->mobile, $this->notifiedBy($captured['schedule']));
    }

    public function test_notifications_stop_entirely_when_the_feature_is_disabled(): void
    {
        // The listener is registered only while FEATURE_NOTIFICATIONS is on, so
        // the guarantee is that the workflow still completes without it.
        config(['features.notifications' => false]);

        $allotments = app(AllotmentService::class);
        $allotments->allot($this->proposal->fresh(), $this->memberOne, $this->chairman, request());

        $this->assertSame('allotted', $allotments->state($this->proposal->fresh()));
        $this->assertTrue(Feature::Notifications->disabled());
    }

    // ------------------------------------------------- closeout verification

    public function test_the_whole_m6_workflow_leaves_a_complete_ordered_timeline(): void
    {
        $this->runFullWorkflow();

        $events = ActivityTimeline::query()
            ->where('subject_id', $this->proposal->id)
            ->where('event', 'like', 'dpc_cgpsc.%')
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();

        $this->assertSame([
            'dpc_cgpsc.allot',
            'dpc_cgpsc.reallot',
            'dpc_cgpsc.accept',
            'dpc_cgpsc.schedule',
            'dpc_cgpsc.reschedule',
        ], $events->pluck('event')->all());

        foreach ($events as $event) {
            $this->assertNotEmpty($event->uuid);
            $this->assertSame(1, $event->schema_version);
            $this->assertNotEmpty($event->actor_uuid);
            $this->assertNotEmpty($event->actor_name);
        }
    }

    public function test_timeline_ordering_is_deterministic_within_a_single_second(): void
    {
        // `occurred_at` has second granularity, so events written in one
        // transaction share a timestamp. Without the UUID tiebreak the order
        // is whatever the database chose — and a history that reorders itself
        // between reads is not a history.
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        $this->runFullWorkflow();

        $read = fn (): array => ActivityTimeline::query()
            ->where('subject_id', $this->proposal->id)
            ->where('event', 'like', 'dpc_cgpsc.%')
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->pluck('uuid')
            ->all();

        $this->assertSame($read(), $read());
        $this->assertCount(5, $read());

        Carbon::setTestNow();
    }
}
