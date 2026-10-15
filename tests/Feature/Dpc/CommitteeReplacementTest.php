<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
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
 * Substituting a committee member (change register of 2026-08-26, item 9e).
 *
 * > *It is also possible that a DPC member can be replaced, so that option is
 * > needed too — changed by the CGPSC Admin.*
 *
 * **The property every test here defends:** a proceeding, a document or a signature
 * that already cites a member stays truthful after that member is replaced. That is
 * only true if the outgoing row is *kept and marked* rather than edited into the
 * successor — so most of what follows asserts what did **not** change.
 */
class CommitteeReplacementTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $admin;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->admin = User::factory()->create(['name' => 'CGPSC Administrator']);
        $this->admin->assignRole(Role::Admin->value);

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();

        /*
         | A **compliant** committee, since business made the composition mandatory
         | on 2026-09-06 and made an incomplete committee block submission.
         |
         | Not decoration: `makeProposalSubmittable()` completes whatever section the
         | checklist reports as unfinished, so an incomplete committee here would be
         | *recomposed* — and would take `Outgoing Member` with it, leaving every
         | test in this class looking for a row that no longer exists.
         |
         | Outgoing Member keeps ST, which the inheritance tests below assert the
         | successor takes over.
         */
        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['name' => 'Outgoing Member', 'mobile' => '9000000101', 'capacity' => 'member', 'is_st' => true],
            ['name' => 'Member Two', 'mobile' => '9000000102', 'capacity' => 'member', 'is_sc' => true],
            ['name' => 'Member Three', 'mobile' => '9000000103', 'capacity' => 'member',
                'is_obc' => true, 'is_female' => true,
                'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
        ], $this->ao);
    }

    private function outgoing(): DpcCommitteeMember
    {
        return DpcCommitteeMember::query()->where('name', 'Outgoing Member')->firstOrFail();
    }

    private function replace(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('admin.committee-members.replace', [$this->proposal, $this->outgoing()]),
            array_replace([
                'name' => 'Incoming Member',
                'mobile' => '9000000201',
                'reason' => 'Transferred out of the department.',
            ], $payload),
        );
    }

    // ------------------------------------------------------------------- form

    /**
     * The substitution form renders, and offers the Designation master.
     *
     * Pinned because the form gained a `$designations` list on 2026-09-06 and the
     * screen was, until then, never rendered by any test — a view variable the
     * controller forgot to pass would have surfaced first in front of the customer.
     */
    public function test_the_substitution_form_offers_the_designation_master(): void
    {
        Designation::query()->create(['name' => 'Joint Secretary', 'status' => MasterStatus::Active->value]);
        Designation::query()->create(['name' => 'Retired Rank', 'status' => MasterStatus::Inactive->value]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.committee-members.replace.form', [$this->proposal, $this->outgoing()]))
            ->assertOk();

        $response->assertSee('name="designation_id"', false);
        $response->assertSee('Joint Secretary');

        // A withdrawn rank must not be offered to a committee being amended today.
        $response->assertDontSee('Retired Rank');
    }

    public function test_the_successors_designation_is_taken_from_the_master(): void
    {
        $designation = Designation::query()->create([
            'name' => 'Joint Secretary',
            'status' => MasterStatus::Active->value,
        ]);

        $this->replace(['designation_id' => $designation->id])->assertSessionHasNoErrors();

        $this->assertSame(
            'Joint Secretary',
            DpcCommitteeMember::query()->where('name', 'Incoming Member')->value('designation'),
        );
    }

    /**
     * Omitting the designation still means *inherit the outgoing member's* — the
     * meaning every blank field on this form has always carried.
     */
    public function test_omitting_the_designation_inherits_the_outgoing_members(): void
    {
        $this->outgoing()->forceFill(['designation' => 'Under Secretary'])->save();

        $this->replace()->assertSessionHasNoErrors();

        $this->assertSame(
            'Under Secretary',
            DpcCommitteeMember::query()->where('name', 'Incoming Member')->value('designation'),
        );
    }

    // ------------------------------------------------------------ substitution

    public function test_the_administrator_replaces_a_member(): void
    {
        $outgoing = $this->outgoing();

        $this->replace()->assertRedirect(route('dpc-proposals.committee', $this->proposal))
            ->assertSessionHasNoErrors();

        $outgoing->refresh();

        // The outgoing member is kept, marked, and no longer serving.
        $this->assertFalse($outgoing->isServing());
        $this->assertTrue($outgoing->wasReplaced());
        $this->assertSame($this->admin->id, $outgoing->replaced_by);
        $this->assertSame('Transferred out of the department.', $outgoing->replacement_reason);

        // The committee is still three besides the Chairman.
        $this->assertSame(4, $this->proposal->committeeMembers()->count());

        $successor = DpcCommitteeMember::query()->where('name', 'Incoming Member')->firstOrFail();
        $this->assertSame($outgoing->id, $successor->replaces_id);
        $this->assertTrue($successor->isServing());
    }

    /**
     * A substitution is normally like-for-like, and a committee that silently lost
     * its only ST member to a blank form would fail its composition rules at the
     * sitting.
     */
    public function test_the_successor_inherits_the_outgoing_members_attributes(): void
    {
        $this->replace()->assertSessionHasNoErrors();

        $successor = DpcCommitteeMember::query()->where('name', 'Incoming Member')->firstOrFail();

        $this->assertTrue($successor->is_st);
    }

    public function test_the_attributes_may_be_overridden(): void
    {
        $this->replace(['is_st' => false, 'is_obc' => true])->assertSessionHasNoErrors();

        $successor = DpcCommitteeMember::query()->where('name', 'Incoming Member')->firstOrFail();

        $this->assertFalse($successor->is_st);
        $this->assertTrue($successor->is_obc);
    }

    public function test_a_reason_is_required(): void
    {
        $this->replace(['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertTrue($this->outgoing()->isServing());
    }

    public function test_a_member_cannot_be_replaced_twice(): void
    {
        $outgoing = $this->outgoing();

        $this->replace()->assertSessionHasNoErrors();

        $this->expectException(RuntimeException::class);

        app(DpcCommitteeService::class)->replace(
            $this->proposal,
            $outgoing->refresh(),
            ['name' => 'Another'],
            $this->admin,
            'Again.',
        );
    }

    public function test_the_replacement_cannot_name_the_member_being_replaced(): void
    {
        $account = User::factory()->create(['email' => 'sitting@cg.gov.in', 'mobile' => '9425000777']);

        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['user_id' => $account->id, 'capacity' => 'member'],
            ['name' => 'Member Two', 'mobile' => '9000000102', 'capacity' => 'member'],
            ['name' => 'Member Three', 'mobile' => '9000000103', 'capacity' => 'member'],
        ], $this->ao);

        $sitting = DpcCommitteeMember::query()->where('user_id', $account->id)->firstOrFail();

        $this->expectException(RuntimeException::class);

        app(DpcCommitteeService::class)->replace(
            $this->proposal, $sitting, ['user_id' => $account->id], $this->admin, 'Pointless.',
        );
    }

    // ----------------------------------------------------------------- staging

    /**
     * Item 9e says *any* stage, so this is pinned after submission — the point at
     * which the Authorized Officer's own committee screen is closed.
     */
    public function test_a_member_may_be_replaced_after_the_proposal_is_submitted(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        /*
         | The officer can no longer edit the committee.
         |
         | The payload is a *valid* committee on purpose. A short one would be
         | refused by the size rule before authorisation ran, and the test would
         | pass while proving nothing about who may edit after submission.
         */
        $this->actingAs($this->ao)->put(route('dpc-proposals.committee.update', $this->proposal), [
            'members' => [
                // Covers the whole required combination on one member (business
                // clarification, 2026-09-06), so nothing but authorisation can
                // refuse this payload.
                ['name' => 'Nope One', 'mobile' => '9000000401', 'designation' => 'Secretary', 'capacity' => 'member', 'is_sc' => true],
                ['name' => 'Nope Two', 'mobile' => '9000000402', 'designation' => 'Secretary', 'capacity' => 'member', 'is_st' => true],
                ['name' => 'Nope Three', 'mobile' => '9000000403', 'designation' => 'Secretary', 'capacity' => 'member',
                    'is_obc' => true, 'is_female' => true,
                    'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
            ],
        ])->assertForbidden();

        // ...but the Administrator may still substitute a member.
        $this->replace()->assertSessionHasNoErrors();

        $this->assertTrue($this->outgoing()->wasReplaced());
    }

    // ------------------------------------------------------------------ history

    /**
     * The reason the outgoing row is kept: a document that cites it stays truthful.
     */
    public function test_a_replaced_member_is_absent_from_the_committee_but_present_in_its_history(): void
    {
        $this->replace()->assertSessionHasNoErrors();

        $serving = $this->proposal->committeeMembers()->pluck('name')->all();
        $everyone = $this->proposal->committeeHistory()->pluck('name')->all();

        $this->assertNotContains('Outgoing Member', $serving);
        $this->assertContains('Outgoing Member', $everyone);
        $this->assertContains('Incoming Member', $serving);
    }

    public function test_the_committee_screen_states_the_substitution(): void
    {
        $this->replace()->assertSessionHasNoErrors();

        $this->actingAs($this->ao)->get(route('dpc-proposals.committee', $this->proposal))
            ->assertOk()
            ->assertSee('Outgoing Member')
            ->assertSee('Transferred out of the department.');
    }

    public function test_the_replacement_is_recorded_on_the_timeline(): void
    {
        $this->replace()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.committee_member_replaced',
            'subject_id' => $this->proposal->id,
        ]);
    }

    /**
     * Re-saving the committee section must not erase a formal substitution.
     */
    public function test_recomposing_the_committee_keeps_the_replaced_rows(): void
    {
        $this->replace()->assertSessionHasNoErrors();

        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['name' => 'Fresh One', 'mobile' => '9000000301', 'capacity' => 'member'],
            ['name' => 'Fresh Two', 'mobile' => '9000000302', 'capacity' => 'member'],
            ['name' => 'Fresh Three', 'mobile' => '9000000303', 'capacity' => 'member'],
        ], $this->ao);

        $this->assertSame(1, $this->proposal->committeeHistory()->whereNotNull('replaced_at')->count());
    }

    // ------------------------------------------------------------------- access

    public function test_only_the_administrator_may_replace_a_member(): void
    {
        $this->actingAs($this->ao)->post(
            route('admin.committee-members.replace', [$this->proposal, $this->outgoing()]),
            ['name' => 'Incoming', 'reason' => 'Trying it on.'],
        )->assertForbidden();

        $this->assertTrue($this->outgoing()->isServing());
    }

    public function test_a_member_of_another_dpc_cannot_be_replaced_through_this_one(): void
    {
        $other = DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'name' => 'Elsewhere',
            'capacity' => CommitteeCapacity::Member->value,
            'sort_order' => 9,
            'active_flag' => 1,
        ]);

        $otherProposal = $this->proposal->replicate(['dpc_code']);
        $otherProposal->dpc_code = 'DPC-2026-HE-0002';
        $otherProposal->save();

        $this->actingAs($this->admin)->get(
            route('admin.committee-members.replace.form', [$otherProposal, $other]),
        )->assertNotFound();
    }
}
