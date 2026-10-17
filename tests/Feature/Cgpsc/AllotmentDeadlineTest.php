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
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * The completion deadline on an allotment, and what happens when it passes
 * (change register of 2026-08-26, item 9 as clarified).
 *
 * > *Record when the DPC was allocated, the date by which it must be concluded, and
 * > the sitting date the member chooses on or before it. The member may reschedule.
 * > If they do not conclude the DPC by the date it was supposed to be concluded, it
 * > returns to the Chairman, who allocates it again.*
 */
class AllotmentDeadlineTest extends TestCase
{
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

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

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

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

        // Since 2026-09-05 the Chairman names who presides as part of allotting.
        $this->seatCommittee($this->proposal);
    }

    protected function tearDown(): void
    {
        // Leaving a frozen clock behind would silently re-date every test that runs
        // after this class in the same process.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function allot(?string $completeBy = '2026-09-30', ?User $to = null): TestResponse
    {
        return $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), array_filter([
            'allotted_to' => ($to ?? $this->memberOne)->id,
            'dpc_chairman_member_id' => $this->presidingSeatId($this->proposal),
            'complete_by' => $completeBy,
        ]));
    }

    // -------------------------------------------------------------- the deadline

    public function test_the_completion_date_is_required(): void
    {
        $this->allot(completeBy: null)->assertSessionHasErrors('complete_by');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    public function test_the_completion_date_is_recorded_alongside_the_allotment_date(): void
    {
        $this->allot()->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->sole();

        $this->assertSame('30-09-2026', $allotment->complete_by?->format('d-m-Y'));
        $this->assertNotNull($allotment->allotted_at);
        $this->assertNull($allotment->returned_at);
    }

    public function test_a_completion_date_in_the_past_is_refused(): void
    {
        // An allotment overdue the moment it is made tells the member nothing and
        // would be swept straight back, producing a loop nobody asked for.
        $this->allot(completeBy: Carbon::yesterday()->toDateString())
            ->assertSessionHasErrors('complete_by');
    }

    public function test_the_deadline_cannot_be_edited_afterwards(): void
    {
        $this->allot()->assertSessionHasNoErrors();

        $allotment = DpcAllotment::query()->sole();

        $this->expectException(\RuntimeException::class);

        $allotment->forceFill(['complete_by' => '2027-01-01'])->save();
    }

    // ---------------------------------------------------------------- who allots

    /**
     * Business clarification of 2026-09-05: *"Every approved DPC is automatically
     * routed to the default CGPSC Chairman. The CGPSC Chairman decides: which
     * member will receive the DPC for execution. Who will act as the DPC Chairman.
     * The Completion Deadline."*
     *
     * **This reverses what the register of 2026-08-26 was read to say**, and the
     * reversal is the point of the test. Under the old reading the CGPSC Chairman
     * lost the ability to allot the moment somebody else was designated — which
     * also cost them the remedies the clarification now gives them by name.
     */
    public function test_the_cgpsc_chairman_may_allot_even_when_somebody_else_presides(): void
    {
        // The seat Member Two already holds — one person has one serving seat, and
        // the database enforces it.
        $designated = $this->designateAsDpcChairman($this->proposal, $this->memberTwo);

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'dpc_chairman_member_id' => $designated->id,
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DpcAllotment::query()->count());
    }

    /**
     * The designated DPC Chairman keeps the ability to allot, which the earlier
     * register gave them. Widening who may act did not narrow it elsewhere.
     */
    public function test_this_dpcs_designated_chairman_may_also_allot_it(): void
    {
        // The seat Member Two already holds — one person has one serving seat, and
        // the database enforces it.
        $designated = $this->designateAsDpcChairman($this->proposal, $this->memberTwo);

        // Holds only Role::Member. The policy answers "could this actor ever
        // allot"; the service answers "may they allot *this* one".
        $this->actingAs($this->memberTwo)->post(route('cgpsc.allotments.store', $this->proposal), [
            'dpc_chairman_member_id' => $designated->id,
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DpcAllotment::query()->count());
    }

    public function test_a_member_who_neither_chairs_the_commission_nor_this_dpc_cannot_allot(): void
    {
        // The seat Member Two already holds — one person has one serving seat, and
        // the database enforces it.
        $designated = $this->designateAsDpcChairman($this->proposal, $this->memberTwo);

        $other = User::factory()->create();
        $other->assignRole(Role::Member->value);

        $this->actingAs($other)->post(route('cgpsc.allotments.store', $this->proposal), [
            'dpc_chairman_member_id' => $designated->id,
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-09-30',
        ])->assertSessionHasErrors('allotment');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    /**
     * The register says so in as many words, so it is pinned rather than assumed.
     */
    public function test_one_member_may_hold_several_dpcs_at_once(): void
    {
        $this->allot()->assertSessionHasNoErrors();

        $second = $this->proposal->replicate(['dpc_code']);
        $second->dpc_code = 'DPC-2026-HE-0002';
        $second->save();

        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $second), [
            'dpc_chairman_member_id' => $this->presidingSeatId($second),
            'allotted_to' => $this->memberOne->id,
            'complete_by' => '2026-10-31',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DpcAllotment::query()->where('allotted_to', $this->memberOne->id)->count());
    }

    // ------------------------------------------------------------------- expiry

    public function test_an_unconcluded_dpc_returns_to_the_chairman_after_the_deadline(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        $returned = app(AllotmentService::class)->returnExpired(Carbon::parse('2026-10-01'));

        $this->assertCount(1, $returned);

        $allotment = DpcAllotment::query()->sole();

        $this->assertSame(AllotmentStatus::Returned, $allotment->status);
        $this->assertNotNull($allotment->returned_at);
        $this->assertStringContainsString('30-09-2026', (string) $allotment->return_reason);
    }

    public function test_a_returned_dpc_can_be_allotted_again(): void
    {
        $this->allot()->assertSessionHasNoErrors();
        app(AllotmentService::class)->returnExpired(Carbon::parse('2026-10-01'));

        // The proposal is the Chairman's again, so this is a fresh allotment rather
        // than a re-allotment — `scopeCurrent` has to exclude the returned row for
        // that to be true.
        $this->actingAs($this->chairman)->post(route('cgpsc.allotments.store', $this->proposal), [
            'dpc_chairman_member_id' => $this->presidingSeatId($this->proposal),
            'allotted_to' => $this->memberTwo->id,
            'complete_by' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DpcAllotment::query()->count());
        $this->assertSame($this->memberTwo->id, app(AllotmentService::class)->current($this->proposal)?->allotted_to);
    }

    public function test_a_concluded_dpc_is_never_returned_however_late(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        // The sitting was held and concluded — late, but concluded. The member did
        // the work, and taking it back from them would be wrong.
        DpcProceeding::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'status' => DpcProceedingStatus::ResultApproved->value,
            'promotion_as_on_year' => 2026,
            'conducted_by' => $this->memberOne->id,

            /*
             | The provenance columns are all NOT NULL on purpose — a concluded
             | sitting must be explainable — so a fixture has to supply them.
             | `conducted_by_name` is the actor snapshot: who held the sitting
             | *then*, which diverges from `conducted_by` for a renamed or removed
             | account.
             */
            'conducted_by_name' => $this->memberOne->name,
            'proposal_version' => str_repeat('0', 64),
            'opened_at' => Carbon::parse('2026-09-29 11:00:00'),
        ]);

        $returned = app(AllotmentService::class)->returnExpired(Carbon::parse('2026-10-01'));

        $this->assertCount(0, $returned);
        $this->assertSame(AllotmentStatus::Pending, DpcAllotment::query()->sole()->status);
    }

    public function test_an_allotment_inside_its_deadline_is_left_alone(): void
    {
        $this->allot(completeBy: '2026-12-31')->assertSessionHasNoErrors();

        $this->assertCount(0, app(AllotmentService::class)->returnExpired(Carbon::parse('2026-10-01')));
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        $asOf = Carbon::parse('2026-10-01');

        $this->assertCount(1, app(AllotmentService::class)->returnExpired($asOf));

        // The natural failure mode of a scheduled task is running more often than
        // intended, not less.
        $this->assertCount(0, app(AllotmentService::class)->returnExpired($asOf));
    }

    public function test_an_allotment_with_no_deadline_is_never_swept(): void
    {
        // Allotments made before this register carry none. There is no date to be
        // late for, and inventing one would make history retrospectively overdue.
        DpcAllotment::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'allotted_to' => $this->memberOne->id,
            'allotted_by' => $this->chairman->id,
            'allotted_at' => now(),
            'status' => AllotmentStatus::Pending->value,
        ]);

        $this->assertCount(0, app(AllotmentService::class)->returnExpired(Carbon::parse('2030-01-01')));
    }

    public function test_the_command_reports_and_returns(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        $this->artisan('dpc:return-expired-allotments', ['--as-of' => '2026-10-01', '--dry-run' => true])
            ->assertSuccessful();

        // A dry run changes nothing.
        $this->assertSame(AllotmentStatus::Pending, DpcAllotment::query()->sole()->status);

        $this->artisan('dpc:return-expired-allotments', ['--as-of' => '2026-10-01'])->assertSuccessful();

        $this->assertSame(AllotmentStatus::Returned, DpcAllotment::query()->sole()->status);
    }

    // --------------------------------------------------------------- scheduling

    public function test_the_sitting_must_fall_on_or_before_the_completion_date(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal))
            ->assertSessionHasNoErrors();

        // Past the deadline: refused, and — since the clarification of 2026-09-05 —
        // refused **against the date box**, so the allottee is not sent back to an
        // empty field with a banner at the top of the page.
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-10-15', 'dpc_time' => '11:00',
        ])->assertSessionHasErrors('dpc_date');

        // On the deadline itself: accepted. "On or before" includes the day.
        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-09-30', 'dpc_time' => '11:00',
        ])->assertSessionHasNoErrors();
    }

    /**
     * *"This reassignment must be completely auditable."* The automatic return
     * appears on the allotment history beside the allotment it ended, so a reader
     * can see why a DPC moved rather than finding two allotments and no reason.
     */
    public function test_the_automatic_return_appears_on_the_allotment_history(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        app(AllotmentService::class)->returnExpired(Carbon::parse('2026-10-01'));

        $events = app(AllotmentService::class)->historyFromTimeline($this->proposal->fresh());

        $this->assertContains('dpc_cgpsc.allotment_returned', $events->pluck('event')->all());

        $this->actingAs($this->chairman)
            ->get(route('cgpsc.allotments.history', $this->proposal))
            ->assertOk()
            ->assertSee(__('cgpsc.allotments.history.returned'))
            ->assertSee('Member One');
    }

    /**
     * *"If rescheduled, the new meeting date must also remain within the Completion
     * Deadline."* — otherwise re-scheduling would be a way out of the deadline, and
     * the return-to-Chairman rule that rests on it would never fire.
     */
    public function test_a_reschedule_is_bounded_by_the_same_deadline(): void
    {
        $this->allot(completeBy: '2026-09-30')->assertSessionHasNoErrors();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.accept', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.store', $this->proposal), [
            'dpc_date' => '2026-09-20', 'dpc_time' => '11:00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-11-05', 'dpc_time' => '11:00',
            'reschedule_reason' => 'Members unavailable.',
        ])->assertSessionHasErrors('dpc_date');

        $this->actingAs($this->memberOne)->post(route('cgpsc.schedules.reschedule', $this->proposal), [
            'dpc_date' => '2026-09-28', 'dpc_time' => '11:00',
            'reschedule_reason' => 'Members unavailable.',
        ])->assertSessionHasNoErrors();
    }
}
