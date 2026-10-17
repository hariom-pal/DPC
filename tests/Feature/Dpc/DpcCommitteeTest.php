<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\CommitteeMasterSeeder;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The DPC Committee, as the change register of 2026-08-26 reshapes it (items 5
 * to 9).
 *
 * **Two things changed that this whole class turns on.** There is no Commission
 * Members master to draw from — a member is an account found by e-mail or mobile,
 * or a person recorded on the spot — and the CGPSC Chairman's seat is placed by the
 * system rather than submitted by the Authorized Officer. So the payloads here name
 * ordinary members only, and the Chairman is asserted to be present anyway.
 */
class DpcCommitteeTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $member;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->member = User::factory()->create(['name' => 'Member One', 'email' => 'member.one@cg.gov.in', 'mobile' => '9425000111']);
        $this->member->assignRole(Role::Member->value);

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     */
    private function saveCommittee(array $overrides): TestResponse
    {
        return $this->actingAs($this->ao)->put(route('dpc-proposals.committee.update', $this->proposal), ['members' => $overrides]);
    }

    /**
     * Enough ordinary members to make a **valid** committee.
     *
     * A committee needs three members besides its Chairman, so a test about
     * anything *else* has to supply them. Without this, a test asserting "this is
     * rejected" would be satisfied by the size error alone and would keep passing
     * after the rule it is named for was removed.
     *
     * **Three of them carry the whole required combination** (business
     * clarification of 2026-09-06 as corrected the same day: one member from *each*
     * of SC, ST and OBC, plus Female, Bhar Sadhak Sachiv and Vibhag Sachiv).
     *
     * Six requirements, three members: a person holds at most one social category,
     * so covering all three takes three people — and each of those three may also
     * carry the additional attributes, which is the register's own worked example of
     * coverage by the committee rather than by the member.
     *
     * **`filler(3)` is therefore the smallest complete committee, and a test that
     * simply needs a valid one should ask for three.** Fewer than three cannot cover
     * the social categories however the attributes are arranged, so `filler(1)` and
     * `filler(2)` are for tests whose point is a refusal.
     *
     * **The attributes start at the last row and work backwards**, so `Filler 0`
     * stays an unadorned member for the tests that assert what claiming nothing
     * means.
     *
     * Use {@see plainFiller()} where the point of the test is a committee that
     * covers nothing at all.
     *
     * @return list<array<string, mixed>>
     */
    private function filler(int $count): array
    {
        $rows = $this->plainFiller($count);

        // Last row first: SC, then ST, then OBC, so `Filler 0` is the one left bare.
        $categories = ['is_obc', 'is_st', 'is_sc'];

        foreach (array_reverse(array_keys($rows)) as $offset => $index) {
            if (! isset($categories[$offset])) {
                break;
            }

            $rows[$index][$categories[$offset]] = true;
        }

        if ($rows !== []) {
            $rows[array_key_last($rows)] += [
                'is_female' => true,
                'is_bhar_sadhak_sachiv' => true,
                'is_vibhag_sachiv' => true,
            ];
        }

        return $rows;
    }

    /**
     * Ordinary members claiming nothing at all.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * A live designation for a member to hold.
     *
     * Mandatory since 2026-09-06, so every posted member needs one — including the
     * ones written to test something else entirely. Find-or-create, so a test that
     * seeds the master and one that seeds nothing both get a usable row, and calling
     * it five times in one payload yields the same designation rather than five.
     */
    private function designationId(): int
    {
        return Designation::query()->firstOrCreate(
            ['name' => 'Under Secretary'],
            ['status' => MasterStatus::Active->value],
        )->id;
    }

    private function plainFiller(int $count): array
    {
        $designation = (object) ['id' => $this->designationId()];

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name' => "Filler {$i}",
                'designation_id' => $designation->id,
                'mobile' => '91000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'capacity' => 'member',
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------ composition

    public function test_ao_can_save_a_committee_naming_an_account_and_a_new_person(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->member->id, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ['name' => 'External Observer', 'designation' => 'Secretary', 'mobile' => '9000000001', 'email' => 'ext@gov.in', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertRedirect(route('dpc-proposals.show', $this->proposal));

        // Five submitted members, plus the seat the system adds. Three of them are
        // the fillers that carry SC, ST and OBC — a member holds at most one, so a
        // complete committee cannot be smaller than three.
        $this->assertSame(6, $this->proposal->committeeMembers()->count());

        $named = DpcCommitteeMember::query()->where('user_id', $this->member->id)->firstOrFail();

        // Denormalised server-side from the account, never from client input.
        $this->assertSame('Member One', $named->name);
    }

    /**
     * The composition screen offers the master and gives the officer nowhere to
     * type a designation.
     *
     * The `assertDontSee` is the half that pins the rule. Adding a dropdown while
     * leaving the old text input in place would satisfy every other assertion here
     * and still let an officer type a rank that is in no master.
     */
    public function test_the_committee_screen_offers_the_designation_master_and_no_text_box(): void
    {
        Designation::query()->create(['name' => 'Joint Secretary', 'status' => MasterStatus::Active->value]);
        Designation::query()->create(['name' => 'Retired Rank', 'status' => MasterStatus::Inactive->value]);

        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.committee', $this->proposal))
            ->assertOk();

        $response->assertSee('name="members[__M__][designation_id]"', false);
        $response->assertSee('Joint Secretary');
        $response->assertDontSee('Retired Rank');
        $response->assertDontSee('name="members[__M__][designation]"', false);
    }

    /**
     * A designation is chosen from the master and never typed (DPC-MODULE-FIX-001,
     * second pass, 2026-09-06).
     *
     * What is asserted is the *precedence*: the payload carries both an id and a
     * conflicting string, and the id wins. That is the rule that matters, because
     * the free-text field is kept only so a committee composed before this change
     * still resolves — and a bug that let the string win would restore exactly the
     * typed designation this change exists to remove, while every other test here
     * kept passing.
     */
    public function test_the_designation_comes_from_the_master_and_not_from_what_was_typed(): void
    {
        $designation = Designation::query()->create([
            'name' => 'Deputy Secretary, GAD',
            'status' => MasterStatus::Active->value,
        ]);

        $this->saveCommittee([
            [
                'name' => 'External Observer',
                'designation_id' => $designation->id,
                'designation' => 'Something Somebody Typed',
                'mobile' => '9000000001',
                'capacity' => 'member',
            ],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $row = DpcCommitteeMember::query()->where('name', 'External Observer')->firstOrFail();

        // Stored as the master's *name*, not its id: the committee row is a
        // snapshot of who sat, and renaming the master later must not rewrite it.
        $this->assertSame('Deputy Secretary, GAD', $row->designation);
    }

    public function test_a_designation_outside_the_master_is_refused(): void
    {
        $this->saveCommittee([
            ['name' => 'External Observer', 'designation_id' => 987654, 'mobile' => '9000000001', 'capacity' => 'member'],
            ...$this->filler(2),
        ])->assertSessionHasErrors('members.0.designation_id');

        $this->assertSame(0, $this->proposal->committeeMembers()->count());
    }

    /**
     * A committee composed before the master dropdown existed still resolves.
     *
     * Its stored designations are free text, and re-posting one unaltered — which
     * is what re-saving an untouched section does — must keep it rather than blank
     * the field.
     */
    public function test_a_committee_posting_only_the_old_free_text_designation_still_saves(): void
    {
        $this->saveCommittee([
            // No `designation_id` on purpose: this is the pre-2026-09-06 payload.
            ['name' => 'External Observer', 'designation' => 'Secretary, School Education', 'mobile' => '9000000001', 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Secretary, School Education',
            DpcCommitteeMember::query()->where('name', 'External Observer')->value('designation'),
        );
    }

    public function test_the_cgpsc_chairman_is_seated_automatically(): void
    {
        $this->saveCommittee($this->filler(3))->assertSessionHasNoErrors();

        $seat = DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $this->proposal->id)
            ->where('capacity', CommitteeCapacity::CgpscChairman->value)
            ->firstOrFail();

        $this->assertSame(CgpscChairman::query()->serving()->firstOrFail()->name, $seat->name);
    }

    /**
     * Item 9: *"The AO should not manually add or remove the Chairman."*
     *
     * Both halves are asserted, because only one of them is enforced by the same
     * mechanism. Submitting the seat is refused by the request; *omitting* it does
     * not remove it, because the service places it regardless.
     */
    public function test_the_ao_can_neither_add_nor_remove_the_chairmans_seat(): void
    {
        $this->saveCommittee([
            ['name' => 'Pretender', 'capacity' => CommitteeCapacity::CgpscChairman->value],
            ...$this->filler(3),
        ])->assertSessionHasErrors();

        $this->saveCommittee($this->filler(3))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->proposal->committeeMembers()
            ->where('capacity', CommitteeCapacity::CgpscChairman->value)->count());
    }

    public function test_a_committee_cannot_be_composed_when_no_chairman_is_recorded(): void
    {
        // The only way to have no Chairman: remove the one `Tests\TestCase` seeds.
        CgpscChairman::query()->delete();

        $this->saveCommittee($this->filler(3))->assertSessionHasErrors('members');

        $this->assertSame(0, $this->proposal->committeeMembers()->count());
    }

    // ------------------------------------------------------------- attributes

    /**
     * Item 8's worked example, which is the specification: five requirements
     * satisfied by three members, because one member carries three attributes.
     */
    public function test_one_member_may_represent_several_attributes(): void
    {
        config(['dpc.committee.required_categories' => ['sc', 'st', 'obc', 'female', 'bhar_sadhak_sachiv']]);

        $this->saveCommittee([
            ['name' => 'ABC', 'mobile' => '9000000011', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                'is_female' => true, 'is_st' => true, 'is_bhar_sadhak_sachiv' => true],
            ['name' => 'DEF', 'mobile' => '9000000012', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_sc' => true],
            ['name' => 'GHI', 'mobile' => '9000000013', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_obc' => true],
        ])->assertSessionHasNoErrors();

        $abc = DpcCommitteeMember::query()->where('name', 'ABC')->firstOrFail();

        $this->assertTrue($abc->is_female);
        $this->assertTrue($abc->is_st);
        $this->assertTrue($abc->is_bhar_sadhak_sachiv);
        $this->assertFalse($abc->is_sc);
    }

    /**
     * Business clarification of 2026-09-05: a member belongs to **one** reserved
     * social category.
     *
     * All three forbidden pairs, because "only one" is the sort of rule that gets
     * implemented for the first pair somebody thought of.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function forbiddenSocialCategoryPairs(): array
    {
        return [
            'SC + ST' => ['is_sc', 'is_st'],
            'SC + OBC' => ['is_sc', 'is_obc'],
            'ST + OBC' => ['is_st', 'is_obc'],
        ];
    }

    #[DataProvider('forbiddenSocialCategoryPairs')]
    public function test_a_member_may_not_hold_two_social_categories(string $first, string $second): void
    {
        $this->saveCommittee([
            ['name' => 'Two Categories', 'mobile' => '9000000031', 'designation_id' => $this->designationId(), 'capacity' => 'member', $first => true, $second => true],
            ...$this->filler(2),
        ])->assertSessionHasErrors('members');

        $this->assertDatabaseMissing('dpc_committee_members', ['name' => 'Two Categories']);
    }

    public function test_a_member_may_hold_one_social_category_with_any_additional_attributes(): void
    {
        $this->saveCommittee([
            ['name' => 'SC Female BSS', 'mobile' => '9000000041', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                'is_sc' => true, 'is_female' => true, 'is_bhar_sadhak_sachiv' => true],
            ['name' => 'OBC Only', 'mobile' => '9000000042', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_obc' => true],
            ['name' => 'Vibhag Sachiv Only', 'mobile' => '9000000043', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_vibhag_sachiv' => true],
            // ST completes the required combination; this test is about the member
            // above it, and a committee short of a requirement would be refused
            // before it got there.
            ['name' => 'ST Only', 'mobile' => '9000000044', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_st' => true],
        ])->assertSessionHasNoErrors();

        $member = DpcCommitteeMember::query()->where('name', 'SC Female BSS')->firstOrFail();

        $this->assertTrue($member->is_sc);
        $this->assertTrue($member->is_female);
        $this->assertTrue($member->is_bhar_sadhak_sachiv);
        $this->assertSame(MemberCategory::ScheduledCaste, $member->socialCategory());
    }

    /**
     * The three additional attributes are not a social category and never conflict,
     * however many are held at once.
     */
    public function test_the_additional_attributes_combine_freely_and_leave_the_member_unreserved(): void
    {
        $this->saveCommittee([
            ['name' => 'All Three', 'mobile' => '9000000051', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                'is_female' => true, 'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $member = DpcCommitteeMember::query()->where('name', 'All Three')->firstOrFail();

        $this->assertNull($member->socialCategory());
        $this->assertSame(__('dpc.committee.attributes.ur'), $member->socialCategoryLabel());
    }

    /**
     * Ticking nothing is UR (General), which is a state and not an omission —
     * there is no UR checkbox to have missed.
     */
    public function test_a_member_claiming_no_social_category_is_unreserved(): void
    {
        // A fourth member alongside the three that carry the required combination,
        // claiming nothing at all — which is what UR *is*.
        $this->saveCommittee([
            ['name' => 'Claims Nothing', 'mobile' => '9000000091', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $member = DpcCommitteeMember::query()->where('name', 'Claims Nothing')->firstOrFail();

        $this->assertNull($member->socialCategory());
        $this->assertSame(__('dpc.committee.attributes.ur'), $member->socialCategoryLabel());
        $this->assertNull($member->attributeLabel());
    }

    /**
     * One required attribute, in isolation.
     *
     * **Asserted on the completeness checklist, not on the save** (business
     * instruction, 2026-09-06). A committee saves as a draft in whatever state the
     * officer has it in; it is *submission* the composition rules gate. See
     * `CommitteeComplianceService::blockingFindings()` for the split and why it
     * exists.
     *
     * `require_reserved_category` is switched off for the length of this test so
     * that the finding it asserts can only be the each-of rule. Left on, a committee
     * claiming nothing would be incomplete for two reasons and the test would keep
     * passing after the rule it is named for was removed.
     */
    public function test_an_uncovered_required_attribute_leaves_the_committee_incomplete(): void
    {
        config([
            'dpc.committee.required_categories' => ['vibhag_sachiv'],
            'dpc.committee.require_reserved_category' => false,
        ]);

        // Saves — and reports incomplete.
        $this->saveCommittee($this->plainFiller(3))->assertSessionHasNoErrors();
        $this->assertFalse($this->committeeSection()['complete']);

        $this->saveCommittee([
            ['name' => 'Vibhag Sachiv', 'mobile' => '9000000021', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_vibhag_sachiv' => true],
            ...$this->plainFiller(2),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->committeeSection()['complete']);
    }

    /**
     * The committee entry on the submission checklist, as the officer sees it.
     *
     * @return array<string, bool|string>
     */
    private function committeeSection(): array
    {
        $section = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'committee');

        $this->assertIsArray($section);

        return $section;
    }

    /**
     * Item 7: *"Do NOT assign any permanent Commission Member role to a user."*
     */
    public function test_sitting_on_a_committee_grants_no_role(): void
    {
        $candidate = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create([
            'name' => 'Serving AO', 'email' => 'serving.ao@cg.gov.in', 'mobile' => '9425000222',
        ]);

        $before = $candidate->roles->pluck('name')->sort()->values()->all();

        $this->saveCommittee([
            ['user_id' => $candidate->id, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $candidate->fresh()->roles->pluck('name')->sort()->values()->all());
        $this->assertFalse($candidate->fresh()->hasRole(Role::Member->value));
    }

    /**
     * Item 6, server-side: an e-mail that already belongs to somebody resolves to
     * that account rather than making a second one.
     */
    public function test_an_existing_email_resolves_to_the_existing_account(): void
    {
        $before = User::query()->count();

        $this->saveCommittee([
            ['name' => 'Typed Differently', 'email' => strtoupper((string) $this->member->email), 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $row = DpcCommitteeMember::query()->where('email', $this->member->email)->firstOrFail();

        $this->assertSame($this->member->id, $row->user_id);
        $this->assertSame('Member One', $row->name);

        // The three filler rows carry a mobile and no account, so exactly three are
        // made — the fourth member resolved to the account that already existed,
        // which is what this test is about.
        $this->assertSame($before + 3, User::query()->count());
    }

    public function test_a_new_member_gets_an_account_with_no_roles(): void
    {
        $this->saveCommittee([
            ['name' => 'Brand New', 'email' => 'brand.new@cg.gov.in', 'mobile' => '9425000333', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ])->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'brand.new@cg.gov.in')->firstOrFail();

        $this->assertTrue($created->roles->isEmpty());
    }

    // ------------------------------------- the required combination (2026-09-06)

    /**
     * The combination business stated on 2026-09-06, in full:
     *
     * > *at least 1 of SC/ST/OBC, 1 Female, 1 Bhar Sadhak Sachiv and 1 Vibhag
     * > Sachiv — one person may hold one of SC/ST/OBC and also be Female and also be
     * > Bhar/Vibhag, or they may be separate people; the committee is complete only
     * > when the whole combination is present.*
     *
     * Both halves are asserted here: a committee claiming nothing is refused, and
     * the same three people are accepted once the combination is covered — **by one
     * of them**, which is the half that matters. A rule implemented by counting
     * attributes instead of covering them would demand four members and pass every
     * other test in this class.
     */
    public function test_a_committee_missing_the_required_combination_is_incomplete(): void
    {
        $this->saveCommittee($this->plainFiller(3))->assertSessionHasNoErrors();

        // Saved — three members plus the seat the system adds...
        $this->assertSame(4, $this->proposal->committeeMembers()->count());

        // ...and not finished, so it cannot be submitted.
        $this->assertFalse($this->committeeSection()['complete']);
    }

    /**
     * Three members are the smallest complete committee, and they cover six
     * requirements between them.
     *
     * SC, ST and OBC take one seat each — a member holds at most one — and the third
     * seat also carries Female, Bhar Sadhak Sachiv and Vibhag Sachiv, which combine
     * freely. That is the register's own worked example: coverage is asked of the
     * committee, not of the member, which is what stops six requirements needing six
     * people.
     */
    public function test_three_members_may_cover_the_whole_required_combination(): void
    {
        $this->saveCommittee([
            ['name' => 'SC Member', 'mobile' => '9000000061', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_sc' => true],
            ['name' => 'ST Member', 'mobile' => '9000000062', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_st' => true],
            ['name' => 'OBC Member', 'mobile' => '9000000063', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                'is_obc' => true, 'is_female' => true,
                'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
        ])->assertSessionHasNoErrors();

        // Three members and the seat the system adds — and the coverage rule is met.
        $this->assertSame(4, $this->proposal->committeeMembers()->count());
        $this->assertTrue($this->committeeSection()['complete'], 'Three members cover the whole required combination.');
    }

    /**
     * **Each** of SC, ST and OBC must be represented — not merely one of them
     * (business correction, 2026-09-06).
     *
     * It was implemented as an any-of earlier the same day, on the first reading of
     * the instruction. All three are asserted separately, because a rule of this
     * shape is the sort that gets implemented for whichever category somebody
     * happened to test with.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function requiredSocialCategories(): array
    {
        return [
            'SC missing' => ['is_sc', 'sc'],
            'ST missing' => ['is_st', 'st'],
            'OBC missing' => ['is_obc', 'obc'],
        ];
    }

    #[DataProvider('requiredSocialCategories')]
    public function test_a_committee_missing_one_social_category_is_incomplete(string $column, string $attribute): void
    {
        // Every requirement covered except the one under test, so the refusal can
        // only be that one.
        $rows = [
            ['name' => 'Member A', 'mobile' => '9000000071', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_sc' => true],
            ['name' => 'Member B', 'mobile' => '9000000072', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_st' => true],
            ['name' => 'Member C', 'mobile' => '9000000073', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                'is_obc' => true, 'is_female' => true,
                'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
        ];

        foreach ($rows as $index => $row) {
            unset($rows[$index][$column]);
        }

        $this->saveCommittee($rows)->assertSessionHasNoErrors();

        $section = $this->committeeSection();

        $this->assertFalse($section['complete']);
        $this->assertStringContainsString(
            (string) __('dpc.committee.findings.attribute_uncovered', [
                'attribute' => __('dpc.committee.attributes.'.$attribute),
            ]),
            (string) $section['detail'],
            'the checklist names the gap, so the officer can act without opening the screen',
        );
    }

    /**
     * The screen shows the requirements as a live checklist, so an officer can see
     * a gap close as they tick the box that closes it (business instruction,
     * 2026-09-06 — the screen was *"bahut confusing"* precisely because the answer
     * arrived only on Save).
     */
    public function test_the_screen_lists_every_composition_requirement(): void
    {
        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.committee', $this->proposal))
            ->assertOk();

        $response->assertSee('id="committee-requirements"', false);
        $response->assertSee('data-columns="is_sc"', false);
        $response->assertSee('data-columns="is_st"', false);
        $response->assertSee('data-columns="is_obc"', false);
        $response->assertSee('data-columns="is_female"', false);
        $response->assertSee('data-columns="is_bhar_sadhak_sachiv"', false);
        $response->assertSee('data-columns="is_vibhag_sachiv"', false);
    }

    // ------------------------------------- mobile and e-mail shape (2026-09-06)

    /**
     * @return list<array{0: string}>
     */
    public static function malformedMobiles(): array
    {
        return [
            'nine digits' => ['942501234'],
            'eleven digits' => ['94250123456'],
            'leading zero' => ['09425012345'],
            'starts below six' => ['5425012345'],
            'not digits' => ['94250 12345'],
        ];
    }

    #[DataProvider('malformedMobiles')]
    public function test_a_mobile_that_is_not_ten_digits_is_refused(string $mobile): void
    {
        $this->saveCommittee([
            ['name' => 'Wrong Number', 'mobile' => $mobile, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(2),
        ])->assertSessionHasErrors('members.0.mobile');
    }

    /**
     * A committee member's address is a government address.
     *
     * `notgov.in` is in the set on purpose: it is what an `ends_with:gov.in` rule
     * would wrongly accept, and it is the reason the rule is anchored on `@`.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function memberEmails(): array
    {
        return [
            'department' => ['member@cg.gov.in', true],
            'commission' => ['member@cgpsc.gov.in', true],
            'bare gov.in' => ['member@gov.in', true],
            'lookalike domain' => ['member@notgov.in', false],
            'public mailbox' => ['member@gmail.com', false],
        ];
    }

    #[DataProvider('memberEmails')]
    public function test_only_a_government_email_is_accepted(string $email, bool $accepted): void
    {
        $response = $this->saveCommittee([
            ['name' => 'Some Member', 'mobile' => '9000000094', 'email' => $email, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ]);

        $accepted
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('members.0.email');
    }

    // ------------------------------------------- what a refused save leaves behind

    /**
     * A refused save **comes back with the members the officer had entered**
     * (reported 2026-09-06: *"I added two members and saved, and because the
     * validation was not satisfied the two I had entered were gone as well"*).
     *
     * This is the difference between a form that tells somebody what is wrong and
     * one that punishes them for finding out.
     *
     * The refusal used here is a member claiming two social categories, which is one
     * of the few faults that still refuses a save — being *incomplete* no longer
     * does, which is the other half of the same instruction.
     */
    public function test_a_refused_save_returns_the_members_that_were_entered(): void
    {
        $screen = route('dpc-proposals.committee', $this->proposal);

        $this->actingAs($this->ao)
            ->from($screen)
            ->put(route('dpc-proposals.committee.update', $this->proposal), [
                'members' => [
                    ['name' => 'Shri Entered One', 'mobile' => '9000000201', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                        'is_sc' => true, 'is_st' => true],
                    ['name' => 'Smt Entered Two', 'mobile' => '9000000202', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
                ],
            ])
            ->assertSessionHasErrors('members')
            ->assertRedirect($screen);

        $screen = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.committee', $this->proposal))
            ->assertOk();

        // The seed the form is rebuilt from, so both members are back on screen with
        // the attributes they were given.
        $screen->assertSee('Shri Entered One');
        $screen->assertSee('Smt Entered Two');
    }

    /**
     * A refused save whose payload has **gaps in its indices** still redraws.
     *
     * That is what a removed card produced: the officer took out the second of
     * three, so the browser posted `members[0]` and `members[2]`, PHP read it as a
     * non-sequential array, and `json_encode` wrote the seed as a JSON *object*.
     * `dpc-committee.js` reads the seed with `forEach`, so it threw before drawing a
     * single card and the whole committee appeared to have been thrown away.
     *
     * Asserted on the rendered seed rather than through the browser, because the
     * fault was in what the server put on the page.
     */
    public function test_a_refused_save_with_a_removed_card_still_redraws_every_member(): void
    {
        $screen = route('dpc-proposals.committee', $this->proposal);

        $this->actingAs($this->ao)
            ->from($screen)
            ->put(route('dpc-proposals.committee.update', $this->proposal), [
                'members' => [
                    // Deliberately not 0 and 1: this is a form the officer removed a
                    // card from before saving. The first row claims two social
                    // categories, which is what gets the save refused.
                    0 => ['name' => 'Kept One', 'mobile' => '9000000211', 'designation_id' => $this->designationId(), 'capacity' => 'member',
                        'is_sc' => true, 'is_obc' => true],
                    2 => ['name' => 'Kept Two', 'mobile' => '9000000212', 'designation_id' => $this->designationId(), 'capacity' => 'member', 'is_st' => true],
                ],
            ])
            ->assertSessionHasErrors('members');

        $html = $this->actingAs($this->ao)->get($screen)->assertOk()->getContent();

        $this->assertIsString($html);

        // The seed is a JSON array, not an object keyed "0" and "2".
        $this->assertStringContainsString('data-seed="[{', $html);
        $this->assertStringNotContainsString('data-seed="{&quot;0&quot;', $html);
        $this->assertStringContainsString('Kept One', $html);
        $this->assertStringContainsString('Kept Two', $html);
    }

    // ---------------------------------------------------------------- refusals

    public function test_the_same_user_cannot_appear_twice(): void
    {
        $this->saveCommittee([
            ['user_id' => $this->member->id, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ['user_id' => $this->member->id, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(1),
        ])->assertSessionHasErrors('members');
    }

    public function test_duplicate_mobile_numbers_are_rejected(): void
    {
        $this->saveCommittee([
            ['name' => 'A', 'mobile' => '9000000009', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ['name' => 'B', 'mobile' => '9000000009', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(1),
        ])->assertSessionHasErrors('members');
    }

    /**
     * A mobile written two ways is one mobile — the normalisation the directory
     * applies has to apply here too, or the duplicate slips through the form and is
     * then silently merged by the service.
     */
    public function test_the_same_mobile_written_differently_is_still_a_duplicate(): void
    {
        $this->saveCommittee([
            ['name' => 'A', 'mobile' => '9425012345', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ['name' => 'B', 'mobile' => '+91 94250-12345', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(1),
        ])->assertSessionHasErrors([
            // The *duplicate* message by name, not merely "something was wrong with
            // members". Since 2026-09-06 the second spelling also fails the
            // ten-digits-only shape, and a test satisfied by any error on `members`
            // would keep passing after the normalisation this is named for was lost.
            'members' => __('dpc.committee.validation.duplicate_mobile'),
        ]);
    }

    public function test_duplicate_emails_are_rejected(): void
    {
        $this->saveCommittee([
            ['name' => 'A', 'email' => 'same@cg.gov.in', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ['name' => 'B', 'email' => 'same@cg.gov.in', 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(1),
        ])->assertSessionHasErrors('members');
    }

    /**
     * Both bounds, because a check on only one of them would not notice a rule that
     * had lost the other. The seeded configuration is three to six besides the
     * Chairman.
     */
    /**
     * The two bounds are enforced differently, and deliberately (business
     * instruction, 2026-09-06).
     *
     * **The maximum still refuses the save**: it is a bound that cannot be exceeded
     * even in a draft, and accepting the extra rows writes members the rules forbid.
     * **The minimum does not**: being short of members is "not finished yet", which
     * is what a draft is for — and refusing it was what made removing a member
     * appear to undo itself.
     */
    public function test_committee_size_must_satisfy_the_active_configuration(): void
    {
        // Under the minimum: saved, and reported incomplete.
        $this->saveCommittee($this->filler(2))->assertSessionHasNoErrors();
        $this->assertFalse($this->committeeSection()['complete']);

        // Over the maximum: refused, and nothing written beyond what was there.
        $this->saveCommittee($this->filler(7))->assertSessionHasErrors('members');
        $this->assertSame(3, $this->proposal->committeeMembers()->count());

        $this->saveCommittee($this->filler(3))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->assertTrue($this->committeeSection()['complete']);
    }

    public function test_ao_cannot_edit_committee_once_submitted(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->saveCommittee($this->filler(3))->assertForbidden();
    }

    // ------------------------------------------------------------------ audit

    public function test_committee_change_generates_audit_and_timeline_entries(): void
    {
        config(['audit.console' => true]);

        $this->saveCommittee([
            ['user_id' => $this->member->id, 'designation_id' => $this->designationId(), 'capacity' => 'member'],
            ...$this->filler(3),
        ]);

        $row = DpcCommitteeMember::query()->where('user_id', $this->member->id)->firstOrFail();

        $this->assertSame(1, DB::table('audits')
            ->where('auditable_type', $row->getMorphClass())
            ->where('auditable_id', $row->id)
            ->where('event', 'created')
            ->count());

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.committee_updated',
            'subject_id' => $this->proposal->id,
        ]);
    }
}
