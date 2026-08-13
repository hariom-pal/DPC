<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Services\AllotmentService;
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
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Chairman allotment and allotment report (PPT S27–S28) — M6 Phase 1.
 */
class AllotmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        // CGPSC users hold no department — that is the whole point of them.
        $this->chairman = User::factory()->create(['department_id' => null, 'name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['department_id' => null, 'name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

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

    private function allot(?User $to = null, ?string $date = '2026-07-10')
    {
        return $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), array_filter([
            'allotted_to' => ($to ?? $this->memberOne)->id,
            'default_dpc_date' => $date,
        ]));
    }

    // ------------------------------------------------------------- allotment

    public function test_the_chairman_can_allot_a_forwarded_proposal(): void
    {
        $this->allot()->assertRedirect(route('cgpsc.allotments.index'))->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->firstOrFail();

        $this->assertSame($this->memberOne->id, $allotment->allotted_to);
        $this->assertSame($this->chairman->id, $allotment->allotted_by);
        $this->assertSame(AllotmentStatus::Pending, $allotment->status);
        $this->assertSame('2026-07-10', $allotment->default_dpc_date?->toDateString());
        $this->assertNull($allotment->superseded_by_id);
    }

    public function test_the_default_dpc_date_is_optional_and_never_derived(): void
    {
        // Architect Decision 2: manual Chairman input, no invented rule. A
        // proposal dated 25/06/2026 must not acquire a date on its own.
        $this->allot(date: null)->assertSessionHasNoErrors();

        $this->assertNull(DpcAllotment::query()->firstOrFail()->default_dpc_date);
    }

    public function test_a_proposal_not_yet_forwarded_cannot_be_allotted(): void
    {
        $this->proposal->forceFill([
            'status' => DpcProposalStatus::Submitted->value,
            'forwarded_at' => null,
        ])->save();

        $this->allot()->assertSessionHasErrors('allotment');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_dpc_cannot_be_allotted_twice(): void
    {
        $this->allot();
        $this->allot($this->memberTwo)->assertSessionHasErrors('allotment');

        $this->assertSame(1, DpcAllotment::query()->count());
    }

    public function test_a_dpc_may_be_allotted_to_the_chairman_himself(): void
    {
        // S28 allows the Chairman to take a DPC himself.
        $this->allot($this->chairman)->assertSessionHasNoErrors();

        $this->assertSame($this->chairman->id, DpcAllotment::query()->firstOrFail()->allotted_to);
    }

    public function test_a_dpc_cannot_be_allotted_to_a_non_cgpsc_user(): void
    {
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $this->ao->id,
        ])->assertSessionHasErrors('allotment');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_dpc_cannot_be_allotted_to_a_deactivated_account(): void
    {
        $this->memberOne->forceFill(['is_active' => false])->save();

        $this->allot()->assertSessionHasErrors();

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_members_are_resolved_dynamically_not_limited_to_five(): void
    {
        // Architect Decision 1: the PPT's M1–M5 columns are a layout, not a
        // business restriction.
        foreach (range(1, 7) as $n) {
            User::factory()->create(['department_id' => null, 'name' => "Extra Member {$n}"])
                ->assignRole(Role::Member->value);
        }

        $response = $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))->assertOk();

        foreach (range(1, 7) as $n) {
            $response->assertSee("Extra Member {$n}");
        }
    }

    // ---------------------------------------------------------- re-allotment

    public function test_re_allotment_creates_a_new_record_and_supersedes_the_old(): void
    {
        $this->allot();
        $first = DpcAllotment::query()->firstOrFail();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DpcAllotment::query()->count());

        $first->refresh();
        $second = DpcAllotment::query()->latest('id')->firstOrFail();

        // The old record survives untouched apart from being superseded.
        $this->assertSame(AllotmentStatus::Superseded, $first->status);
        $this->assertSame($second->id, $first->superseded_by_id);
        $this->assertSame($this->memberOne->id, $first->allotted_to);

        $this->assertSame($this->memberTwo->id, $second->allotted_to);
        $this->assertSame('Member One is on leave.', $second->reallotment_reason);
    }

    public function test_re_allotment_requires_a_reason(): void
    {
        $this->allot();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
        ])->assertSessionHasErrors('reallotment_reason');

        $this->assertSame(1, DpcAllotment::query()->count());
    }

    public function test_re_allotting_to_the_same_member_is_refused(): void
    {
        $this->allot();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberOne->id,
            'reallotment_reason' => 'No change.',
        ])->assertSessionHasErrors('allotment');
    }

    public function test_an_unallotted_dpc_cannot_be_re_allotted(): void
    {
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Nothing to move.',
        ])->assertSessionHasErrors('allotment');
    }

    // ------------------------------------------------------------ immutability

    public function test_an_allotment_cannot_be_edited(): void
    {
        $this->allot();
        $allotment = DpcAllotment::query()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be edited');

        $allotment->update(['allotted_to' => $this->memberTwo->id]);
    }

    public function test_an_allotment_cannot_be_deleted(): void
    {
        $this->allot();

        $this->expectException(RuntimeException::class);

        DpcAllotment::query()->firstOrFail()->delete();
    }

    // -------------------------------------------------- audit and timeline

    public function test_allotment_is_audited_and_recorded_on_the_timeline(): void
    {
        $this->allot();

        $this->assertGreaterThan(0, DpcAllotment::query()->firstOrFail()->audits()->count());

        $this->assertTrue(
            ActivityTimeline::query()
                ->where('subject_type', $this->proposal->getMorphClass())
                ->where('subject_id', $this->proposal->id)
                ->where('event', 'dpc_cgpsc.allot')
                ->exists(),
        );

        $this->assertSame(1, WorkflowTransition::query()
            ->where('workflow', 'dpc_cgpsc')
            ->where('subject_id', $this->proposal->id)
            ->count());
    }

    public function test_the_workflow_ledger_records_the_state_change(): void
    {
        $this->allot();

        $transition = WorkflowTransition::query()->where('workflow', 'dpc_cgpsc')->firstOrFail();

        $this->assertSame('allot', $transition->event);
        $this->assertSame('unallotted', $transition->from_state);
        $this->assertSame('allotted', $transition->to_state);
        $this->assertSame($this->chairman->id, $transition->actor_id);
    }

    public function test_history_is_reproducible_from_timeline_records_alone(): void
    {
        // Architect directive: workflow history must always be reproducible
        // entirely from Timeline records, never reconstructed from current
        // state. This asserts the timeline carries enough on its own.
        $this->allot();
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ]);

        $events = app(AllotmentService::class)->historyFromTimeline($this->proposal);

        $this->assertCount(2, $events);

        $first = $events->first()->properties;
        $this->assertSame('Member One', $first['allotted_to_name']);
        $this->assertSame('Hon. Chairman', $first['allotted_by_name']);
        $this->assertSame('2026-07-10', $first['default_dpc_date']);

        $second = $events->last()->properties;
        $this->assertSame('Member Two', $second['allotted_to_name']);
        // The supersession is visible without reading the allotment table.
        $this->assertSame('Member One', $second['previous_allotted_to_name']);
        $this->assertSame('Member One is on leave.', $second['remarks']);
    }

    public function test_the_history_screen_renders_from_the_timeline(): void
    {
        $this->allot();
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ]);

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.history', $this->proposal))
            ->assertOk()
            ->assertSee('Member One')
            ->assertSee('Member Two')
            ->assertSee('Re-allotted')
            ->assertSee('Member One is on leave.');
    }

    // ------------------------------------------------------------ notifications

    public function test_the_allotted_member_is_notified_alongside_the_department(): void
    {
        // The allottee holds no department, so the role-plus-department query
        // that serves AO/AH can never reach them — they must be named.
        Event::fake([WorkflowTransitioned::class]);

        $this->allot();

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event): bool {
            return $event->workflow === 'dpc_cgpsc'
                && $event->event === 'allot'
                && in_array('authorized_officer', $event->notifyRoles, true)
                && in_array('administrative_head', $event->notifyRoles, true)
                && in_array($this->memberOne->id, $event->notifyUserIds, true);
        });
    }

    public function test_re_allotment_notifies_both_the_new_and_the_outgoing_member(): void
    {
        $this->allot();

        Event::fake([WorkflowTransitioned::class]);

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ]);

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event): bool {
            return in_array($this->memberTwo->id, $event->notifyUserIds, true)
                && in_array($this->memberOne->id, $event->notifyUserIds, true);
        });
    }

    // ----------------------------------------------------------- authorization

    public function test_a_member_cannot_allot(): void
    {
        $this->actingAs($this->memberOne)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
        ])->assertForbidden();

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_department_officer_cannot_reach_the_allotment_area(): void
    {
        $this->actingAs($this->ao)->get(route('cgpsc.allotments.index'))->assertForbidden();
        $this->actingAs($this->ao)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $this->memberOne->id,
        ])->assertForbidden();
    }

    public function test_a_member_may_view_the_allotment_screen(): void
    {
        $this->allot();

        $this->actingAs($this->memberOne)->get(route('cgpsc.allotments.index'))->assertOk();
    }

    // ---------------------------------------------------------------- report

    public function test_the_allotment_report_shows_status_and_default_dpc_date(): void
    {
        $this->allot();

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))
            ->assertOk()
            ->assertSee('DPC Allotment Report')
            ->assertSee('HE202600001')
            ->assertSee('Member One')
            ->assertSee('Pending')
            ->assertSee('10/07/26');
    }

    public function test_an_unforwarded_proposal_does_not_appear_at_all(): void
    {
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value, 'forwarded_at' => null])->save();

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))
            ->assertOk()
            ->assertDontSee('HE202600001');
    }

    public function test_the_service_layer_refuses_an_unforwarded_proposal_even_bypassing_http(): void
    {
        $this->proposal->forceFill(['forwarded_at' => null])->save();

        $this->expectException(WorkflowException::class);

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(),
            $this->memberOne,
            $this->chairman,
            request(),
        );
    }
}
