<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\DpcType;
use App\Enums\Role;
use Database\Seeders\CommitteeMasterSeeder;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

class DpcCommitteeTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $chairman;

    private User $member;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->chairman = User::factory()->create(['name' => 'Hon. K.R. Pisda']);
        $this->chairman->assignRole(Role::Chairman->value);
        $this->member = User::factory()->create(['name' => 'Member One']);
        $this->member->assignRole(Role::Member->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     */
    private function saveCommittee(array $overrides): TestResponse
    {
        return $this->actingAs($this->ao)->put(route('dpc-proposals.committee.update', $this->proposal), ['members' => $overrides]);
    }

    public function test_ao_can_save_a_committee_with_an_internal_and_an_external_member(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
            ['name' => 'External Observer', 'designation' => 'Secretary', 'mobile' => '9000000001', 'email' => 'ext@gov.in', 'capacity' => 'member'],
        ])->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->assertSame(2, $this->proposal->committeeMembers()->count());
        $chairmanRow = DpcCommitteeMember::query()->where('user_id', $this->chairman->id)->firstOrFail();
        $this->assertSame('Hon. K.R. Pisda', $chairmanRow->name); // denormalized server-side from the user, not client input
        $externalRow = DpcCommitteeMember::query()->whereNull('user_id')->firstOrFail();
        $this->assertSame('External Observer', $externalRow->name);
        $this->assertTrue($externalRow->isExternal());
    }

    public function test_committee_change_generates_audit_and_timeline_entries(): void
    {
        config(['audit.console' => true]);

        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
        ]);

        $member = DpcCommitteeMember::query()->firstOrFail();
        $this->assertSame(1, DB::table('audits')->where('auditable_type', $member->getMorphClass())->where('auditable_id', $member->id)->where('event', 'created')->count());
        $this->assertDatabaseHas('activity_timelines', ['event' => 'dpc_proposal.committee_updated', 'subject_id' => $this->proposal->id]);
    }

    public function test_exactly_one_chairman_is_required(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->member->id, 'capacity' => 'member'],
        ])->assertSessionHasErrors('members');

        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
            ['name' => 'Second Chairman', 'capacity' => 'chairman'],
        ])->assertSessionHasErrors('members');

        $this->assertSame(0, $this->proposal->committeeMembers()->count());
    }

    public function test_the_same_user_cannot_appear_twice(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
            ['user_id' => $this->chairman->id, 'capacity' => 'member'],
        ])->assertSessionHasErrors('members');
    }

    public function test_duplicate_mobile_numbers_are_rejected(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
            ['name' => 'A', 'mobile' => '9000000009', 'capacity' => 'member'],
            ['name' => 'B', 'mobile' => '9000000009', 'capacity' => 'member'],
        ])->assertSessionHasErrors('members');
    }

    public function test_committee_size_must_satisfy_the_active_configuration(): void
    {
        $members = [['user_id' => $this->chairman->id, 'capacity' => 'chairman']];
        for ($i = 0; $i < 5; $i++) {
            $members[] = ['name' => "Extra {$i}", 'mobile' => "900000000{$i}", 'capacity' => 'member'];
        }

        // 6 members exceeds the seeded max of 5.
        $this->saveCommittee($members)->assertSessionHasErrors('members');
    }

    public function test_external_members_are_rejected_when_configuration_disallows_them(): void
    {
        CommitteeConfiguration::query()->update(['external_member_allowed' => false]);

        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
            ['name' => 'External Observer', 'mobile' => '9000000002', 'capacity' => 'member'],
        ])->assertSessionHasErrors('members');
    }

    public function test_ao_cannot_edit_committee_once_submitted(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->saveCommittee([
            ['user_id' => $this->chairman->id, 'capacity' => 'chairman'],
        ])->assertForbidden();
    }
}
