<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcStageReminder;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RaisesDpcProposals;
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
        $this->assertSame(['automatic' => true, 'reason' => 'secretary_deadline_passed'], $transition->meta);
        $this->assertSame(DpcStage::Chairman, app(DpcStageService::class)->stageOf($proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))->assertSee('id="secretary-escalated"', false);
    }
}
