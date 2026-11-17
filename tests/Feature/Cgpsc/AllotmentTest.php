<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Proceeding\Services\SealedCoverService;
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
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * Chairman allotment and allotment report (PPT S27–S28) — M6 Phase 1.
 */
class AllotmentTest extends TestCase
{
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private Department $department;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | **Freeze the clock, because this class is a fixed timeline.**
         |
         | Every test here allots with `complete_by` 30-09-2026 and sweeps from
         | 01-10-2026 — dates chosen when they were comfortably ahead. `complete_by`
         | is validated `after_or_equal:today`, so on any real day after 30-09-2026
         | the fixture stops being an allotment and becomes a validation error, and
         | the class fails wholesale for a reason that has nothing to do with
         | allotment. It did: 43 tests across this file and AllotmentTest broke on
         | 05-10-2026, five days after the deadline they hard-code.
         |
         | Pinning "today" to 01-07-2026 — the instant the Proceeding tests already
         | use — makes the narrative hold: the proposal is dated 25-06-2026, the
         | allotment is made now, the deadline is three months out, and the sweep
         | runs the day after it. Relative dates were the alternative and were
         | rejected: the assertions name the dates they expect (`30-09-2026`,
         | `30/09/2026`), and a test that computes its own expected value from the
         | same expression as the code cannot catch the code being wrong.
         */
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        // CGPSC users hold no department — that is the whole point of them.
        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

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

        // Since 2026-09-05 the Chairman names who presides as part of allotting,
        // so a proposal reaching this screen always has a committee to name from.
        $this->seatCommittee($this->proposal);
    }

    protected function tearDown(): void
    {
        // Leaving a frozen clock behind would silently re-date every test that runs
        // after this class in the same process.
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * `complete_by` is supplied on every allotment because the change register of
     * 2026-08-26 makes it mandatory — the DPC Chairman states the date the DPC must
     * be concluded by, and the return-to-Chairman rule depends on it. Far enough
     * ahead that no test is accidentally testing an overdue allotment.
     */
    private function allot(?User $to = null, ?string $date = '2026-07-10', ?string $completeBy = '2026-09-30')
    {
        return $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), array_filter([
            'allotted_to' => ($to ?? $this->memberOne)->id,
            'default_dpc_date' => $date,
            'complete_by' => $completeBy,
        ]));
    }

    // ------------------------------------------------------------- allotment

    // ------------------------------------------------- who presides (2026-09-05)

    /**
     * Business instruction of 2026-11-17: "Allot to" and "DPC Chairman" are one
     * choice — the person a DPC is allotted to presides over it.
     */
    public function test_the_allottee_is_the_dpc_chairman(): void
    {
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $covers = app(SealedCoverService::class);
        $this->assertTrue($covers->isDpcChairman($this->proposal, $this->memberOne));
        $this->assertFalse($covers->isDpcChairman($this->proposal, $this->memberTwo));

        $entry = ActivityTimeline::query()->where('subject_id', $this->proposal->id)->where('event', 'dpc_cgpsc.allot')->sole();
        $this->assertSame($this->memberOne->id, $entry->properties['dpc_chairman_user_id']);

        // Re-allotted: the new allottee presides, the old one no longer does.
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'allotted_to' => $this->memberTwo->id,
            'complete_by' => '2026-09-30',
            'reallotment_reason' => 'On leave.',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($covers->isDpcChairman($this->proposal, $this->memberTwo));
        $this->assertFalse($covers->isDpcChairman($this->proposal, $this->memberOne));

        // A legacy presiding flag on a committee seat confers nothing once the DPC
        // has been allotted — not even after the allotment is returned.
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('user_id', $this->memberOne->id)
            ->update(['is_dpc_chairman' => 1]);
        DpcAllotment::query()->current()->update(['returned_at' => now(), 'status' => AllotmentStatus::Returned->value]);
        $this->assertFalse($covers->isDpcChairman($this->proposal, $this->memberOne));
        $this->assertFalse($covers->isDpcChairman($this->proposal, $this->memberTwo));
    }

    public function test_the_chairman_can_allot_a_forwarded_proposal(): void
    {
        $this->allot()->assertRedirect(route('cgpsc.allotments.index'))->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->firstOrFail();

        $this->assertSame($this->memberOne->id, $allotment->allotted_to);
        $this->assertSame($this->chairman->id, $allotment->allotted_by);
        $this->assertSame(AllotmentStatus::Pending, $allotment->status);
        $this->assertNull($allotment->superseded_by_id);

        // The allotment's own date is today by construction — the Chairman is not
        // asked for it (business instruction, 2026-09-06).
        $this->assertTrue($allotment->allotted_at->isToday());
    }

    /**
     * **The suggested DPC date is no longer collected** (business instruction,
     * 2026-09-06: *"when the CGPSC Chairman allots, two dates appear — there should
     * be one"*).
     *
     * It was optional Chairman input and bound nobody: the allottee schedules the
     * sitting themselves, and the only date that governs anything is the completion
     * deadline. Two dates on one row, one required and one not, with nothing on
     * screen saying how they differed.
     *
     * The column stays — allotments already made carry a value in it — and a
     * payload still carrying the field must not write one, which is what this pins.
     */
    public function test_the_suggested_dpc_date_is_no_longer_collected(): void
    {
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-09-30',
            'default_dpc_date' => '2026-07-10',
        ])->assertSessionHasNoErrors();

        $this->assertNull(DpcAllotment::query()->firstOrFail()->default_dpc_date);
    }

    /**
     * Only the Commission's own accounts (business instruction, 2026-11-17: "allot
     * sirf CGPSC staff ko hi hoga, DPC member ko nahi"). A DPC committee member who
     * holds no Commission role is refused, even when seated on this committee.
     */
    public function test_a_committee_member_holding_no_commission_role_cannot_be_allotted(): void
    {
        $plain = User::factory()->create(['name' => 'Smt. Mamta']);
        $this->seatAccountOnCommittee($this->proposal, $plain);

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $plain->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasErrors('allotted_to');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_cgpsc_member_need_not_sit_on_the_committee(): void
    {
        $member = User::factory()->create(['name' => 'Not On This Committee']);
        $member->assignRole(Role::Member->value);

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'allotted_to' => $member->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame($member->id, DpcAllotment::query()->sole()->allotted_to);
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
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->ao->id,
        ])->assertSessionHasErrors('allotted_to');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_dpc_cannot_be_allotted_to_a_deactivated_account(): void
    {
        $this->memberOne->forceFill(['is_active' => false])->save();

        $this->allot()->assertSessionHasErrors();

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    /**
     * One field, "Allot to DPC Chairman", offering the Commission's accounts — not
     * the DPC committee (business instruction, 2026-11-17).
     */
    public function test_the_screen_offers_cgpsc_accounts_in_one_field(): void
    {
        foreach (range(1, 3) as $n) {
            User::factory()->create(['name' => "Extra Member {$n}"])->assignRole(Role::Member->value);
        }

        $response = $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))->assertOk()
            ->assertSee('Allot to DPC Chairman')
            ->assertDontSee('name="dpc_chairman_member_id"', false)
            ->assertDontSee('Committee Member 1');

        foreach (range(1, 3) as $n) {
            $response->assertSee("Extra Member {$n}");
        }
    }

    // ------------------------------------------------ declining (2026-09-06)

    /**
     * The allottee may refuse a DPC, with a reason, and it goes back to the CGPSC
     * Chairman.
     *
     * > *The DPC is allotted; there is an option to accept but none to reject. The
     * > allottee should be able to reject it, and it should go back to the Chairman
     * > with the reason, so the Chairman can allot it to somebody else.*
     *
     * **This replaces architect Decision 3**, which recorded that there was no
     * member-decline transition — leaving the Chairman to notice unaided that a
     * member could not proceed.
     */
    public function test_the_allottee_declines_and_the_dpc_returns_to_the_chairman(): void
    {
        $this->allot();

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.schedules.decline', $this->proposal), [
                'decline_reason' => 'I am on leave for the whole of September.',
            ])
            ->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->firstOrFail();

        $this->assertSame(AllotmentStatus::Declined, $allotment->status);
        $this->assertSame('I am on leave for the whole of September.', $allotment->return_reason);

        // Out of the current scope, so the DPC is the Chairman's again — the same
        // state as one never allotted.
        $this->assertSame('unallotted', app(AllotmentService::class)->state($this->proposal));
        $this->assertNull(app(AllotmentService::class)->current($this->proposal));
    }

    public function test_a_declined_dpc_can_be_allotted_to_somebody_else(): void
    {
        $this->allot();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.decline', $this->proposal), [
            'decline_reason' => 'I am on leave for the whole of September.',
        ]);

        // A fresh allotment, not a re-allotment: there is nothing current to
        // supersede.
        $this->allot($this->memberTwo)->assertSessionHasNoErrors();

        $this->assertSame($this->memberTwo->id, app(AllotmentService::class)->current($this->proposal)?->allotted_to);
        $this->assertSame(2, DpcAllotment::query()->count());
    }

    public function test_declining_requires_a_reason(): void
    {
        $this->allot();

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.schedules.decline', $this->proposal), ['decline_reason' => ''])
            ->assertSessionHasErrors('decline_reason');

        $this->assertSame(AllotmentStatus::Pending, DpcAllotment::query()->firstOrFail()->status);
    }

    /**
     * Only the member it was allotted to may decline it. A DPC refused by somebody
     * else is not a refusal — and the reason recorded would name the wrong person.
     */
    public function test_only_the_allottee_may_decline(): void
    {
        $this->allot();

        $this->actingAs($this->memberTwo)
            ->post(route('cgpsc.schedules.decline', $this->proposal), [
                'decline_reason' => 'Not mine to take.',
            ])
            ->assertForbidden();

        $this->assertSame(AllotmentStatus::Pending, DpcAllotment::query()->firstOrFail()->status);
    }

    /**
     * Declining is the answer to *"I cannot take this on"*, and that answer belongs
     * before the acceptance. Afterwards the remedy is the Chairman's re-allotment.
     */
    public function test_an_accepted_dpc_can_no_longer_be_declined(): void
    {
        $this->allot();
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal));

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.schedules.decline', $this->proposal), [
                'decline_reason' => 'Changed my mind.',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(AllotmentStatus::Accepted, DpcAllotment::query()->firstOrFail()->status);
    }

    // ---------------------------------------------------------- re-allotment

    public function test_re_allotment_creates_a_new_record_and_supersedes_the_old(): void
    {
        $this->allot();
        $first = DpcAllotment::query()->firstOrFail();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'complete_by' => '2026-09-30',
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
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberTwo->id,
        ])->assertSessionHasErrors('reallotment_reason');

        $this->assertSame(1, DpcAllotment::query()->count());
    }

    public function test_re_allotting_to_the_same_member_is_refused(): void
    {
        $this->allot();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberOne->id,
            'reallotment_reason' => 'No change.',
        ])->assertSessionHasErrors('allotment');
    }

    public function test_an_unallotted_dpc_cannot_be_re_allotted(): void
    {
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'complete_by' => '2026-09-30',
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
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ]);

        $events = app(AllotmentService::class)->historyFromTimeline($this->proposal);

        $this->assertCount(2, $events);

        $first = $events->first()->properties;
        $this->assertSame('Member One', $first['allotted_to_name']);
        $this->assertSame('Hon. Chairman', $first['allotted_by_name']);

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
            'complete_by' => '2026-09-30',
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

        // The department side is the DPC's allotted AO and AH (2026-10-26).
        $ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();
        app(ProposalAllotmentService::class)->allot($this->proposal, $this->ao, $ah, null);

        $this->allot();

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event) use ($ah): bool {
            return $event->workflow === 'dpc_cgpsc'
                && $event->event === 'allot'
                && in_array($this->ao->id, $event->notifyUserIds, true)
                && in_array($ah->id, $event->notifyUserIds, true)
                && in_array($this->memberOne->id, $event->notifyUserIds, true);
        });
    }

    public function test_re_allotment_notifies_both_the_new_and_the_outgoing_member(): void
    {
        $this->allot();

        Event::fake([WorkflowTransitioned::class]);

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.reallot', $this->proposal), [
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberTwo->id,
            'reallotment_reason' => 'Member One is on leave.',
        ]);

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event): bool {
            return in_array($this->memberTwo->id, $event->notifyUserIds, true)
                && in_array($this->memberOne->id, $event->notifyUserIds, true);
        });
    }

    // ----------------------------------------------------------- authorization

    /**
     * A Member who is not this DPC's designated Chairman cannot allot it.
     *
     * **Refused by the service rather than by the policy since 2026-08-26.** Item 9
     * gives allotment to the DPC Chairman, who is designated per DPC and commonly
     * holds only `Role::Member` — so the class-level policy cannot answer this and
     * `AllotmentService::assertMayAllot()` does. The refusal is what matters, not
     * which layer produces it.
     */
    public function test_a_member_who_is_not_this_dpcs_chairman_cannot_allot(): void
    {
        $this->actingAs($this->memberOne)->post(route('cgpsc.allotments.store', $this->proposal), [
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberTwo->id,
        ])->assertSessionHasErrors('allotment');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_a_department_officer_cannot_reach_the_allotment_area(): void
    {
        $this->actingAs($this->ao)->get(route('cgpsc.allotments.index'))->assertForbidden();
        $this->actingAs($this->ao)->post(route('cgpsc.allotments.store', $this->proposal), [
            'complete_by' => '2026-09-30',
            'allotted_to' => $this->memberOne->id,
        ])->assertForbidden();
    }

    public function test_a_member_may_view_the_allotment_screen(): void
    {
        $this->allot();

        $this->actingAs($this->memberOne)->get(route('cgpsc.allotments.index'))->assertOk();
    }

    // ---------------------------------------------------------------- report

    public function test_the_allotment_report_shows_status_and_the_completion_date(): void
    {
        $this->allot();

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))
            ->assertOk()
            ->assertSee('DPC Allotment Report')
            ->assertSee('DPC-2026-HE-0001')
            ->assertSee('Member One')
            ->assertSee('Pending')
            // The deadline, which is the only date an allotment now carries.
            ->assertSee('30/09/2026');
    }

    public function test_an_unforwarded_proposal_does_not_appear_at_all(): void
    {
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value, 'forwarded_at' => null])->save();

        $this->actingAs($this->chairman)->get(route('cgpsc.allotments.index'))
            ->assertOk()
            ->assertDontSee('DPC-2026-HE-0001');
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
