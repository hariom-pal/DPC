<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Events\DpcSittingScheduled;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Notification\Models\UserNotification;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * *"Once the DPC Allottee schedules the meeting, automatic SMS and Email
 * notifications shall be sent to: All Committee Members, All Candidates.
 * Notification should contain: DPC Date, DPC Time, Basic Joining Instructions."*
 * — business clarification, 2026-09-05.
 */
class SittingAnnouncementTest extends TestCase
{
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private User $chairman;

    private User $allottee;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->allottee = User::factory()->create(['name' => 'Member One']);
        $this->allottee->assignRole(Role::Member->value);

        $postFrom = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'promotion_to_post_id' => $postTo->id,
            'promotion_from_post_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'DPC-2026-HE-0001',
            'forwarded_at' => now(),
        ]);

        $this->seatCommittee($this->proposal);
    }

    /**
     * A committee member who can actually be reached, and an officer under
     * consideration who holds an account.
     */
    private function reachableCommitteeMember(): DpcCommitteeMember
    {
        $member = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $this->proposal->id)
            ->orderByDesc('sort_order')
            ->firstOrFail();

        $member->forceFill(['mobile' => '9425000777', 'email' => 'committee.member@cg.gov.in'])->save();

        return $member;
    }

    private function namedCandidate(): User
    {
        DpcSeniorityEntry::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'seniority_number' => 1,
            'employee_number' => 'CG900001',
            'name' => 'Shri Candidate One',
            'dob' => '1975-05-05',
            'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
        ]);

        return User::factory()->create([
            'name' => 'Shri Candidate One',
            'employee_number' => 'CG900001',
            'email' => null,
            'mobile' => null,
        ]);
    }

    private function allotAndAccept(): void
    {
        app(AllotmentService::class)->allot(
            $this->proposal->fresh(),
            $this->allottee,
            $this->chairman,
            request(),
            null,
            Carbon::parse('2026-12-31'),
        );

        $this->actingAs($this->allottee)
            ->post(route('cgpsc.schedules.accept', $this->proposal))
            ->assertSessionHasNoErrors();
    }

    private function schedule(string $date = '2026-07-12', string $time = '14:00')
    {
        return $this->actingAs($this->allottee)
            ->post(route('cgpsc.schedules.store', $this->proposal), ['dpc_date' => $date, 'dpc_time' => $time]);
    }

    // ------------------------------------------------------------------- event

    public function test_scheduling_announces_the_sitting(): void
    {
        Event::fake([DpcSittingScheduled::class]);

        $this->allotAndAccept();
        $this->schedule()->assertSessionHasNoErrors();

        Event::assertDispatched(
            DpcSittingScheduled::class,
            fn (DpcSittingScheduled $e): bool => $e->proposal->id === $this->proposal->id && $e->isReschedule === false,
        );
    }

    /**
     * A re-schedule announces itself as a move. Somebody told three times that a
     * DPC is "scheduled" is somebody who attends on the wrong day.
     */
    public function test_rescheduling_announces_itself_as_a_move(): void
    {
        $this->allotAndAccept();
        $this->schedule()->assertSessionHasNoErrors();

        Event::fake([DpcSittingScheduled::class]);

        $this->actingAs($this->allottee)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-07-20', 'dpc_time' => '11:00',
            'reschedule_reason' => 'Members unavailable.',
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(
            DpcSittingScheduled::class,
            fn (DpcSittingScheduled $e): bool => $e->isReschedule === true,
        );
    }

    // ------------------------------------------------------------- the message

    /**
     * The message a committee member receives carries the three things the
     * clarification names: the date, the time, and joining instructions.
     *
     * Asserted on the SMS channel rather than the e-mail one, because `Mail::raw()`
     * is a no-op under `Mail::fake()` — it records nothing to assert against. The
     * two channels are built from the same string in the listener, so proving the
     * SMS proves the e-mail's body too, and the recipient is proved separately by
     * the in-app tests below.
     */
    public function test_committee_members_are_told_the_date_time_and_instructions(): void
    {
        $member = $this->reachableCommitteeMember();

        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);

        $this->allotAndAccept();
        $this->schedule('2026-07-12', '14:00')->assertSessionHasNoErrors();

        $this->assertContains($member->mobile, RecordingSmsSender::mobiles());

        $sent = collect(RecordingSmsSender::$sent)->firstWhere('mobile', $member->mobile);

        $this->assertStringContainsString('12-07-2026', $sent['message']);
        $this->assertStringContainsString('14:00', $sent['message']);
        $this->assertStringContainsString('DPC-2026-HE-0001', $sent['message']);
        $this->assertStringContainsString(
            (string) __('notifications.sitting.joining_instructions'),
            $sent['message'],
        );
    }

    /**
     * A re-schedule says so in as many words. "Scheduled" three times is how
     * somebody attends on the wrong day.
     */
    public function test_the_reschedule_message_says_the_date_moved(): void
    {
        $member = $this->reachableCommitteeMember();

        $this->allotAndAccept();
        $this->schedule('2026-07-12', '14:00')->assertSessionHasNoErrors();

        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);

        $this->actingAs($this->allottee)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-07-20', 'dpc_time' => '11:00',
            'reschedule_reason' => 'Members unavailable.',
        ])->assertSessionHasNoErrors();

        $sent = collect(RecordingSmsSender::$sent)->firstWhere('mobile', $member->mobile);

        $this->assertStringContainsString('20-07-2026', $sent['message']);
        $this->assertStringContainsString('MOVED', $sent['message']);
    }

    public function test_every_participant_with_an_account_gets_an_in_app_notification(): void
    {
        $candidate = $this->namedCandidate();
        $member = $this->reachableCommitteeMember();
        $member->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->allotAndAccept();
        $this->schedule()->assertSessionHasNoErrors();

        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $candidate->id)
            ->where('event', 'sitting_scheduled')
            ->count());

        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $member->refresh()->user_id)
            ->where('event', 'sitting_scheduled')
            ->count());
    }

    /**
     * A candidate has no mobile and no e-mail — a Seniority List carries neither.
     * The in-app copy is what actually reaches them, and it must carry the same
     * date, time and instructions the SMS would have.
     */
    public function test_the_candidates_in_app_copy_carries_the_date_and_the_instructions(): void
    {
        $candidate = $this->namedCandidate();

        $this->allotAndAccept();
        $this->schedule('2026-07-12', '14:00')->assertSessionHasNoErrors();

        $notification = UserNotification::query()->where('user_id', $candidate->id)->sole();

        $this->assertStringContainsString('12-07-2026', (string) $notification->body);
        $this->assertStringContainsString('14:00', (string) $notification->body);
        $this->assertStringContainsString(
            (string) __('notifications.sitting.joining_instructions'),
            (string) $notification->body,
        );
    }

    /**
     * A member who was replaced is not attending, and telling them to would be
     * worse than telling them nothing.
     */
    public function test_a_replaced_committee_member_is_not_announced_to(): void
    {
        $member = $this->reachableCommitteeMember();
        $member->forceFill([
            'user_id' => User::factory()->create()->id,
            'active_flag' => null,
            'replaced_at' => now(),
        ])->save();

        $this->allotAndAccept();
        $this->schedule()->assertSessionHasNoErrors();

        $this->assertSame(0, UserNotification::query()
            ->where('user_id', $member->refresh()->user_id)
            ->count());
    }

    /**
     * The rule every notifier in this system follows: scheduling must never fail
     * because a notification did.
     */
    public function test_a_failing_notification_never_fails_the_scheduling(): void
    {
        $this->reachableCommitteeMember();

        // A transport that throws on every send. Scheduling must survive it.
        $this->app->bind(SmsSender::class, fn (): SmsSender => new class implements SmsSender
        {
            public function send(string $mobile, string $message): void
            {
                throw new \RuntimeException('the gateway is down');
            }
        });

        $this->allotAndAccept();
        $this->schedule()->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-07-12',
            DpcSchedule::query()->sole()->dpc_date->toDateString(),
        );
    }
}
