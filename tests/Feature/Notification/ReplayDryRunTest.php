<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Notification\Models\UserNotification;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Replay Dry Run — **TD-10, closed**.
 *
 * Designed in `docs/archive/2026-08-19-pre-final/EVENT_CATALOG.md` and deliberately not built at the time. Two
 * properties give it its value, and both are asserted here:
 *
 * 1. **No side effects.** Nothing is dispatched, nothing is written. A dry run that
 *    could send is not a dry run, and the whole point is to be able to look before
 *    turning a subscriber on against months of history.
 * 2. **It surfaces what cannot be replayed** — the catalogue's own closing line. A
 *    transition whose recipients have all been deactivated since is the case a real
 *    replay would silently skip, and it is the case somebody needs to see.
 */
class ReplayDryRunTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education', 'status' => 'active',
        ]);

        $this->ao = User::factory()->create(['name' => 'Shri Anil Verma', 'department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->ah = User::factory()->create(['name' => 'Smt. Sunita Rathore', 'department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->cadre('Deputy Director')->id,
            'post_from_cadre_id' => $this->cadre('Under Secretary')->id,
            'proposal_date' => '2026-06-25',
            'status' => 'approved',
        ]);
    }

    public function test_it_reports_what_would_be_sent_and_to_whom(): void
    {
        $this->ledger('dpc_cgpsc', 'allot', 'unallotted', 'allotted');

        $this->artisan('notifications:replay', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run — nothing was sent')
            ->expectsOutputToContain('notification(s) would be sent')
            ->assertExitCode(0);
    }

    /**
     * The guarantee. Asserted by observing that nothing happened, not by trusting
     * the docblock that says so.
     */
    public function test_it_sends_nothing_and_writes_nothing(): void
    {
        Mail::fake();

        $this->ledger('dpc_cgpsc', 'allot', 'unallotted', 'allotted');
        $this->ledger('pre_dpc', 'submit', 'draft', 'submitted');

        $notificationsBefore = UserNotification::query()->count();
        $ledgerBefore = WorkflowTransition::query()->count();

        $this->artisan('notifications:replay', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($notificationsBefore, UserNotification::query()->count());
        $this->assertSame($ledgerBefore, WorkflowTransition::query()->count());
        Mail::assertNothingSent();
    }

    /**
     * The flag is required even though nothing else is possible — somebody will type
     * a command called "replay" expecting delivery, and a refusal is better than a
     * report they read as an outcome.
     */
    public function test_it_refuses_to_run_without_the_dry_run_flag(): void
    {
        $this->artisan('notifications:replay')
            ->expectsOutputToContain('Refusing to run without --dry-run')
            ->assertExitCode(1);
    }

    /**
     * **The catalogue's closing line: a dry run is most useful when it surfaces what
     * cannot be replayed.**
     */
    public function test_it_names_transitions_whose_recipients_have_all_gone(): void
    {
        $this->ledger('pre_dpc', 'submit', 'draft', 'submitted');

        // `pre_dpc.submit` notifies the Administrative Head. Deactivate them and the
        // transition becomes unreplayable — which a real replay would pass over in
        // silence.
        $this->ah->forceFill(['is_active' => false])->save();

        $this->artisan('notifications:replay', ['--dry-run' => true])
            ->expectsOutputToContain('could not be resolved to a recipient')
            ->expectsOutputToContain('No active recipient holds administrative_head')
            ->assertExitCode(0);
    }

    /** A transition no longer in configuration is itself a finding. */
    public function test_a_transition_that_no_longer_exists_is_reported_rather_than_skipped(): void
    {
        $this->ledger('pre_dpc', 'a_transition_since_removed', 'draft', 'gone');

        $this->artisan('notifications:replay', ['--dry-run' => true])
            ->expectsOutputToContain('no longer exists in the workflow configuration')
            ->assertExitCode(0);
    }

    public function test_the_filters_narrow_the_range(): void
    {
        $this->ledger('dpc_cgpsc', 'allot', 'unallotted', 'allotted', '2026-08-01');
        $this->ledger('pre_dpc', 'submit', 'draft', 'submitted', '2026-08-20');

        $this->artisan('notifications:replay', [
            '--dry-run' => true,
            '--workflow' => 'pre_dpc',
            '--detail' => true,
        ])
            ->expectsOutputToContain('pre_dpc.submit')
            ->doesntExpectOutputToContain('dpc_cgpsc.allot')
            ->assertExitCode(0);

        $this->artisan('notifications:replay', [
            '--dry-run' => true,
            '--from' => '2026-08-15',
            '--detail' => true,
        ])
            ->expectsOutputToContain('pre_dpc.submit')
            ->doesntExpectOutputToContain('dpc_cgpsc.allot')
            ->assertExitCode(0);
    }

    /**
     * Titles come from the same factory the live listener uses. Resolving them a
     * second way would make the dry run a prediction about a different system.
     */
    public function test_the_titles_match_what_the_live_channel_would_write(): void
    {
        $this->ledger('dpc_cgpsc', 'schedule', 'accepted', 'scheduled');

        $this->artisan('notifications:replay', ['--dry-run' => true, '--detail' => true])
            ->expectsOutputToContain('A date has been fixed for your DPC')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function cadre(string $name): Cadre
    {
        return Cadre::query()->firstOrCreate(
            ['department_id' => $this->department->id, 'name' => $name],
            ['class_level' => 'I', 'status' => MasterStatus::Active->value],
        );
    }

    private function ledger(
        string $workflow,
        string $event,
        ?string $from,
        string $to,
        string $occurredAt = '2026-08-10',
    ): WorkflowTransition {
        return WorkflowTransition::query()->create([
            'workflow' => $workflow,
            'subject_type' => $this->proposal->getMorphClass(),
            'subject_id' => $this->proposal->id,
            'event' => $event,
            'from_state' => $from,
            'to_state' => $to,
            'actor_id' => $this->ao->id,
            'occurred_at' => Carbon::parse($occurredAt.' 10:00:00'),
        ]);
    }
}
