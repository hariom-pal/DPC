<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use Database\Seeders\CommitteeMasterSeeder;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The CGPSC Chairman names who conducts each DPC (change register of 2026-08-26,
 * item 9 as clarified).
 *
 * > *During DPC, the CGPSC Chairman will create the DPC Chairman from the DPC
 * > members — or they will become the DPC Chairman themselves.*
 *
 * Two chairmen, and they are not the same chairman: the **CGPSC Chairman** holds a
 * seat on every committee by office, and the **DPC Chairman** is a designation made
 * per DPC on top of a seat. The Chairman's own seat is in the candidate set, which
 * is what lets them take the conduct themselves without a separate operation.
 */
class DpcChairmanDesignationTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private User $cgpscChairman;

    private User $member;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();

        $this->cgpscChairman = User::factory()->create(['name' => 'Hon. CGPSC Chairman']);
        $this->cgpscChairman->assignRole(Role::Chairman->value);

        // The seat is filled from the record, so it has to name this account for
        // "the Chairman may take the conduct themselves" to be testable.
        CgpscChairman::query()->delete();
        $seated = new CgpscChairman([
            'user_id' => $this->cgpscChairman->id,
            'name' => $this->cgpscChairman->name,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        $seated->forceFill(['active_flag' => 1])->save();

        $this->member = User::factory()->create(['name' => 'Shri Committee Member', 'mobile' => '9425000501']);

        $postFrom = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();

        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['user_id' => $this->member->id, 'capacity' => 'member'],
            ['name' => 'Member Two', 'mobile' => '9000000602', 'capacity' => 'member'],
            ['name' => 'Member Three', 'mobile' => '9000000603', 'capacity' => 'member'],
        ], $this->ao);

        // Designation is a Commission act, so the proposal has to have reached it.
        $this->proposal->forceFill(['forwarded_at' => now()])->save();
    }

    private function seat(string $name): DpcCommitteeMember
    {
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $this->proposal->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function designate(DpcCommitteeMember $member, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->cgpscChairman)->post(
            route('cgpsc.committee.designate-chairman', [$this->proposal, $member]),
        );
    }

    // ----------------------------------------------------------- designation

    public function test_no_dpc_chairman_is_designated_to_begin_with(): void
    {
        // Composing a committee does not name a conductor: the register puts that
        // step after the committee exists, and refusing to save a committee without
        // one would make the two steps impossible in the order it describes.
        $this->assertSame(0, $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->count());
    }

    public function test_the_cgpsc_chairman_designates_a_member(): void
    {
        $this->designate($this->seat('Shri Committee Member'))->assertSessionHasNoErrors();

        $designated = $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->sole();

        $this->assertSame('Shri Committee Member', $designated->name);
        $this->assertTrue($designated->isDpcChairman());
    }

    /**
     * *"...or they will become the DPC Chairman themselves."* The Chairman's own seat
     * is in the candidate set, so this is the same operation rather than a special
     * case — which is exactly why `is_dpc_chairman` is a flag beside the capacity
     * and not a third capacity value.
     */
    public function test_the_cgpsc_chairman_may_take_the_conduct_themselves(): void
    {
        $seat = $this->proposal->committeeMembers()
            ->where('capacity', CommitteeCapacity::CgpscChairman->value)
            ->sole();

        $this->designate($seat)->assertSessionHasNoErrors();

        $seat->refresh();

        $this->assertTrue($seat->isDpcChairman());
        $this->assertTrue($seat->isCgpscChairman());
    }

    public function test_designation_moves_rather_than_accumulates(): void
    {
        $first = $this->seat('Shri Committee Member');
        $second = $this->seat('Member Two');

        $this->designate($first)->assertSessionHasNoErrors();
        $this->designate($second)->assertSessionHasNoErrors();

        // `unique(dpc_proposal_id, is_dpc_chairman)` would refuse a second, so the
        // incumbent has to be cleared in the same transaction.
        $this->assertSame(1, $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->count());
        $this->assertSame('Member Two', $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->sole()->name);
    }

    /**
     * Re-saving the committee section must not send the CGPSC Chairman back to
     * redo the designation.
     */
    public function test_recomposing_keeps_the_designation_when_the_person_stays(): void
    {
        $this->designate($this->seat('Shri Committee Member'))->assertSessionHasNoErrors();

        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['user_id' => $this->member->id, 'capacity' => 'member'],
            ['name' => 'Someone New', 'mobile' => '9000000701', 'capacity' => 'member'],
            ['name' => 'Another New', 'mobile' => '9000000702', 'capacity' => 'member'],
        ], $this->ao);

        $this->assertSame(
            'Shri Committee Member',
            $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->sole()->name,
        );
    }

    // --------------------------------------------------------------- refusals

    public function test_the_candidate_set_is_this_committee_and_nothing_wider(): void
    {
        $otherProposal = $this->proposal->replicate(['dpc_code']);
        $otherProposal->dpc_code = 'DPC-2026-HE-0002';
        $otherProposal->save();

        $stranger = DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $otherProposal->id,
            'name' => 'Elsewhere',
            'capacity' => CommitteeCapacity::Member->value,
            'sort_order' => 1,
            'active_flag' => 1,
        ]);

        $this->expectException(RuntimeException::class);

        app(DpcCommitteeService::class)->designateChairman($this->proposal, $stranger, $this->cgpscChairman);
    }

    public function test_a_replaced_member_cannot_be_made_dpc_chairman(): void
    {
        $outgoing = $this->seat('Member Two');

        app(DpcCommitteeService::class)->replace(
            $this->proposal, $outgoing, ['name' => 'Successor', 'mobile' => '9000000801'],
            $this->cgpscChairman, 'Stood down.',
        );

        $this->expectException(RuntimeException::class);

        app(DpcCommitteeService::class)->designateChairman($this->proposal, $outgoing->refresh(), $this->cgpscChairman);
    }

    /**
     * The designation is the CGPSC Chairman's. A Commission Member designating one
     * is exactly the thing the register gives to the Chairman.
     */
    public function test_a_member_cannot_designate(): void
    {
        $someMember = User::factory()->create();
        $someMember->assignRole(Role::Member->value);

        $this->designate($this->seat('Shri Committee Member'), $someMember)->assertForbidden();

        $this->assertSame(0, $this->proposal->committeeMembers()->whereNotNull('is_dpc_chairman')->count());
    }

    public function test_a_department_officer_cannot_designate(): void
    {
        $this->designate($this->seat('Shri Committee Member'), $this->ao)->assertForbidden();
    }

    // ------------------------------------------------------------------ audit

    public function test_the_designation_is_recorded_on_the_timeline(): void
    {
        $this->designate($this->seat('Shri Committee Member'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.dpc_chairman_designated',
            'subject_id' => $this->proposal->id,
        ]);
    }

    /**
     * Being designated is not a role grant — item 7 forbids committee membership
     * from conferring one, and conducting a DPC is a membership with a job.
     */
    public function test_designation_grants_no_role(): void
    {
        $before = $this->member->grantedRoleNames();

        $this->designate($this->seat('Shri Committee Member'))->assertSessionHasNoErrors();

        $this->assertSame($before, $this->member->fresh()->grantedRoleNames());
    }
}
