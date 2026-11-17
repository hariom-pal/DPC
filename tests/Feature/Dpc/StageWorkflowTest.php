<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcStageReminder;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * MoM of 16 November 2026, points 5 to 8: the Next Process panel, the
 * Administrator's stage timelines with their reminders, the Secretary's overdue
 * stage escalated to the Chairman, and Pending With You / Pending At Me.
 */
class StageWorkflowTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Mail::fake();

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($department->id, Role::AdministrativeHead)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
    }

    private function setDays(array $days): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $this->actingAs($admin)->get(route('admin.stage-timelines.index'))->assertOk()->assertSee('id="stage-timelines"', false);
        $this->put(route('admin.stage-timelines.update'), ['days' => $days])->assertSessionHasNoErrors();
    }

    public function test_the_administrator_sets_each_stages_days(): void
    {
        $this->setDays(['ao' => '5', 'ah' => '3', 'secretary' => '2', 'chairman' => '', 'committee' => '3']);

        $this->assertSame(5, WorkflowStageTimeline::daysFor(DpcStage::Ao));
        $this->assertSame(2, WorkflowStageTimeline::daysFor(DpcStage::Secretary));
        $this->assertNull(WorkflowStageTimeline::daysFor(DpcStage::Chairman), 'Blank: no deadline.');

        $this->put(route('admin.stage-timelines.update'), ['days' => ['ao' => '0']])->assertSessionHasErrors('days.ao');
    }

    public function test_the_next_process_and_the_pending_lists_show_whose_move_it_is_and_by_when(): void
    {
        $this->setDays(['ao' => '5']);

        // The AO: the move is theirs, next to the AH, with the deadline.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))->assertOk()
            ->assertSee('id="next-process" data-stage="ao"', false)
            ->assertSee('data-next-stage="ah"', false)
            ->assertSee(__('dpc.stage.then', ['next' => DpcStage::Ah->label()]))
            ->assertSee('id="next-process-task"', false);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('id="pending-with-you"', false)
            ->assertSee('data-pending="'.$this->proposal->uuid.'" data-overdue="no"', false);

        // The AH: theirs too, but waiting on somebody else.
        $this->assertSame([], app(DpcStageService::class)->pendingWith($this->ah));
        $this->assertCount(1, app(DpcStageService::class)->pendingAt($this->ah));
        $this->actingAs($this->ah)->get(route('dpc-proposals.show', $this->proposal))->assertDontSee('id="next-process-task"', false);
        // Only where there is something (2026-11-17): the AH has nothing with them, and the list says what is pending.
        $this->get(route('dashboard'))->assertDontSee('id="pending-with-you"', false)
            ->assertSee('id="pending-at-me"', false)
            ->assertSee(__('dpc.stage.items.submit'));

        // Past the deadline: highlighted.
        $this->travel(6)->days();
        $this->actingAs($this->ao)->get(route('dashboard'))->assertSee('data-pending="'.$this->proposal->uuid.'" data-overdue="yes"', false);
    }

    public function test_a_stage_near_or_past_its_deadline_is_reminded_once_a_day(): void
    {
        $this->setDays(['ao' => '5']);

        $this->travel(2)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(0, DpcStageReminder::query()->count(), 'Three days left: nothing yet.');

        $this->travel(2)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(1, DpcStageReminder::query()->where('kind', 'reminder')->count(), 'A day left: once that day.');

        $this->travel(3)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(1, DpcStageReminder::query()->where('kind', 'overdue')->where('stage', 'ao')->count());
        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->fresh()->status, 'Only the Secretary\'s stage is escalated.');
    }

    public function test_the_secretarys_overdue_stage_goes_to_the_chairman_automatically(): void
    {
        $this->setDays(['secretary' => '2']);
        $this->proposal->forceFill(['status' => DpcProposalStatus::PendingSecretary->value])->save();
        WorkflowTransition::query()->create([
            'workflow' => 'dpc_proposal', 'subject_type' => $this->proposal->getMorphClass(), 'subject_id' => $this->proposal->id,
            'event' => 'approve', 'from_state' => 'submitted', 'to_state' => 'pending_secretary', 'actor_id' => $this->ah->id, 'occurred_at' => now(),
        ]);

        $this->travel(1)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status, 'Not before the deadline.');

        $this->travel(2)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();

        $proposal = $this->proposal->fresh();
        $this->assertSame(DpcProposalStatus::Approved, $proposal->status);
        $this->assertNotNull($proposal->secretary_escalated_at);
        $this->assertNull($proposal->secretary_approved_by, 'Nobody signed for the Secretary.');
        $transition = WorkflowTransition::query()->where('event', 'secretary_escalated')->sole();
        $this->assertNull($transition->actor_id);
        $this->assertSame(['automatic' => true, 'reason' => 'stage_deadline_passed', 'deadline_type' => 'workflow_stage', 'stage' => 'secretary'], $transition->meta);
        $this->assertSame(DpcStage::Chairman, app(DpcStageService::class)->stageOf($proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))->assertSee('id="secretary-escalated"', false)
            ->assertSee('data-automatic-action="secretary_escalated"', false);
    }

    private function atStage(DpcProposalStatus $status, string $from): void
    {
        $this->proposal->forceFill(['status' => $status->value])->save();
        WorkflowTransition::query()->create([
            'workflow' => 'dpc_proposal', 'subject_type' => $this->proposal->getMorphClass(), 'subject_id' => $this->proposal->id,
            'event' => 'test', 'from_state' => $from, 'to_state' => $status->value, 'actor_id' => $this->ao->id, 'occurred_at' => now(),
        ]);
    }

    /** 2026-11-17: the Administrator chooses each stage's action once its deadline passes. */
    public function test_each_stage_does_what_the_administrator_chose_once_its_deadline_passes(): void
    {
        // Only what the stage allows: nothing follows the committee, so no forward there.
        $this->setDays(['committee' => '3']);
        $this->put(route('admin.stage-timelines.update'), ['days' => ['committee' => '3'], 'on_expiry' => ['committee' => 'forward']])
            ->assertSessionHasErrors('on_expiry.committee');
        $this->get(route('admin.stage-timelines.index'))->assertSee('data-stage-expiry="ah"', false)
            ->assertSee(__('admin.stage_timelines.actions.forward', ['next' => DpcStage::Secretary->label()]));

        // The AH's stage forwards to the Secretary.
        $this->put(route('admin.stage-timelines.update'), ['days' => ['ah' => '2'], 'on_expiry' => ['ah' => 'forward']])->assertSessionHasNoErrors();
        $this->atStage(DpcProposalStatus::Submitted, 'draft');
        $this->travel(3)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertNull(WorkflowTransition::query()->where('event', 'ah_auto_forward')->sole()->actor_id);

        // The Secretary's stage set to cancel: cancelled.
        $this->put(route('admin.stage-timelines.update'), ['days' => ['secretary' => '1'], 'on_expiry' => ['secretary' => 'cancel']])->assertSessionHasNoErrors();
        $this->travel(2)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::Cancelled, $this->proposal->fresh()->status);
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'auto_cancel')->count());
    }

    /** 2026-11-17: after the deadline, reminders as often as set, by the channels set. */
    public function test_reminders_follow_the_frequency_and_channels_set(): void
    {
        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);
        $this->ao->forceFill(['mobile' => '9425000999'])->save();

        $this->setDays(['ao' => '1']);
        $this->put(route('admin.stage-timelines.update'), ['days' => ['ao' => '1'], 'every' => ['ao' => '3'], 'email' => ['ao' => '0'], 'sms' => ['ao' => '1']])->assertSessionHasNoErrors();

        $this->travel(2)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertContains('9425000999', RecordingSmsSender::mobiles(), 'By SMS, as set.');

        $this->travel(1)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(1, DpcStageReminder::query()->where('kind', 'overdue')->count(), 'Not again within three days.');

        $this->travel(1)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(2, DpcStageReminder::query()->where('kind', 'overdue')->count(), 'Again on the third day.');
        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->fresh()->status, 'Reminding only: nothing else happens.');
    }

    private function allotMember(User $member, string $completeBy, ?DpcAllotment $previous = null): DpcAllotment
    {
        $allotment = DpcAllotment::query()->forceCreate([
            'dpc_proposal_id' => $this->proposal->id, 'allotted_to' => $member->id, 'allotted_by' => $this->ao->id,
            'allotted_at' => now(), 'complete_by' => $completeBy, 'status' => 'accepted', 'accepted_at' => now(),
        ]);
        $previous?->forceFill(['superseded_by_id' => $allotment->id, 'status' => 'superseded'])->save();

        return $allotment;
    }

    /**
     * 2026-11-17: the stage's Workflow Timeline and the member's completion date are
     * independent — a reassignment does not reset the stage, a returned allotment
     * keeps the DPC at the committee stage (the Chairman's timeline does not take
     * over), and the stage's deadline never returns or cancels an allotment.
     */
    public function test_the_committee_timeline_and_the_member_allotment_deadline_are_independent(): void
    {
        $this->setDays(['committee' => '10', 'chairman' => '1']);
        $this->put(route('admin.stage-timelines.update'), ['days' => ['committee' => '10', 'chairman' => '1'], 'on_expiry' => ['chairman' => 'cancel']])->assertSessionHasNoErrors();
        $this->atStage(DpcProposalStatus::Approved, 'pending_secretary');
        $stages = app(DpcStageService::class);
        $memberA = User::factory()->create();
        $memberB = User::factory()->create();

        $this->travel(1)->days();
        $first = $this->allotMember($memberA, now()->addDays(3)->toDateString());
        $due = $stages->deadline($this->proposal->fresh())['due_at'];
        $this->assertSame(DpcStage::Committee, $stages->stageOf($this->proposal->fresh()));

        // The member's completion date passes: the allotment goes back to the Chairman.
        $this->travel(5)->days();
        $this->artisan('dpc:return-expired-allotments')->assertSuccessful();
        $this->assertNotNull($first->fresh()->returned_at);
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $proposal = $this->proposal->fresh();
        $this->assertSame(DpcStage::Committee, $stages->stageOf($proposal), 'Still the committee stage, not the Chairman\'s.');
        $this->assertSame(DpcProposalStatus::Approved, $proposal->status, 'The Chairman\'s Cancel never applies while it waits for reassignment.');
        $this->assertTrue($stages->awaitingReassignment($proposal));
        $this->assertContains(__('dpc.stage.items.reassign'), $stages->pendingItems($proposal, DpcStage::Committee));
        $this->assertSame($due->toDateTimeString(), $stages->deadline($proposal)['due_at']->toDateTimeString());

        // Reassigned to another member with a new date: the stage's deadline stays.
        $this->allotMember($memberB, now()->addDays(20)->toDateString());
        $this->assertFalse($stages->awaitingReassignment($proposal->fresh()));
        $this->assertSame($due->toDateTimeString(), $stages->deadline($proposal->fresh())['due_at']->toDateTimeString(), 'Not reset by the reassignment.');

        // The stage's deadline passes: a reminder only; the member's allotment stands.
        $this->travel(6)->days();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(1, DpcStageReminder::query()->where('stage', 'committee')->where('kind', 'overdue')->count());
        $this->assertTrue(DpcAllotment::query()->where('dpc_proposal_id', $this->proposal->id)->current()->where('allotted_to', $memberB->id)->exists());
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->fresh()->status);

        // The history tells the two deadlines apart.
        $returned = ActivityTimeline::query()->where('event', 'dpc_cgpsc.allotment_returned')->sole();
        $this->assertSame('committee_member_allotment', $returned->properties['deadline_type'] ?? null);
    }
}
