<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PayScale;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpensPreDpcs;
use Tests\Concerns\PrescribesQualifications;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\TestCase;

/**
 * The UI must not offer an action the backend will refuse.
 *
 * Every case here asserts the pair, never just one half: the button is absent
 * for the role that cannot use it, **and** the route still refuses that role.
 * Asserting only the absence would let a later change quietly drop the backend
 * check and keep the test green — which is the failure mode that makes
 * "hide the button" security theatre rather than usability.
 *
 * The backend assertions are the pre-existing behaviour and are expected to
 * pass before and after; the visibility assertions are what this suite adds.
 */
class RoleBasedUiVisibilityTest extends TestCase
{
    use OpensPreDpcs;
    use PrescribesQualifications;
    use RefreshDatabase;
    use SeatsDpcCommittees;

    private User $ao;

    private User $ah;

    private User $chairman;

    private User $member;

    private DepartmentPost $postFrom;

    private DepartmentPost $postTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        // A Pre-DPC names two existing posts since change items 8-11, so both must
        // be registered before anything can be drafted.
        // Each on a scale, the feeder's lower (MoM of 2026-10-29).
        $this->postFrom = DepartmentPost::query()->create([
            'department_id' => $department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'promotion_duration_years' => 5, 'status' => 'active',
            'pay_scale_id' => PayScale::query()->create(['code' => 'L10', 'label' => 'Level-10', 'minimum_pay' => 56100, 'status' => 'active'])->id,
        ]);
        $this->postTo = DepartmentPost::query()->create([
            'department_id' => $department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
            'pay_scale_id' => PayScale::query()->create(['code' => 'L12', 'label' => 'Level-12', 'minimum_pay' => 78800, 'status' => 'active'])->id,
        ]);

        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();

        $this->ah = User::factory()->assignedTo($department->id, Role::AdministrativeHead)->create();

        // CGPSC users hold no department: a DPC reaches the Commission
        // precisely by leaving its department's control.
        $this->chairman = User::factory()->create();
        $this->chairman->assignRole(Role::Chairman->value);

        $this->member = User::factory()->create();
        $this->member->assignRole(Role::Member->value);
    }

    // ------------------------------------------------------------------
    // Pre-DPC — authoring is the Authorized Officer's (PPT S7)
    // ------------------------------------------------------------------

    /**
     * Opening a Pre-DPC is the Department Admin's (A1, 2026-10-26): neither the AO
     * nor the AH is offered it, and the Department Admin's screen is.
     */
    public function test_no_officer_is_offered_a_new_pre_dpc_and_the_department_admin_is(): void
    {
        $open = route('department.allotments.pre-dpc.create');

        foreach ([$this->ao, $this->ah] as $officer) {
            $this->actingAs($officer)->get(route('pre-dpc.index'))
                ->assertOk()
                ->assertDontSee($open, escape: false);
        }

        $this->actingAs($this->departmentAdminOf($this->ao->department_id))->get(route('department.allotments.index', ['view' => 'pre-dpc']))
            ->assertOk()
            ->assertSee($open, escape: false);
    }

    public function test_officers_are_refused_opening_a_pre_dpc_by_the_backend(): void
    {
        foreach ([$this->ao, $this->ah] as $officer) {
            $this->actingAs($officer)->get(route('department.allotments.pre-dpc.create'))->assertForbidden();
            $this->actingAs($officer)->post(route('department.allotments.pre-dpc.store'), [
                'dpc_year' => 2026,
                'promotion_to_post_id' => $this->postTo->id,
                'ao_user_id' => $this->ao->id,
                'ah_user_id' => $this->ah->id,
            ])->assertForbidden();
        }

        $this->assertSame(0, PreDpcProposal::query()->count());
    }

    /**
     * Authoring is the AO's at **every** entry point, not just at creation.
     *
     * TD-26 closed 2026-08-18. `create` and `store` were gated on 2026-08-18;
     * `edit`, `update` and `amend` were not, and checked only the department —
     * so an Administrative Head could open the edit form and save over a draft,
     * even though the show screen has never offered them the button. The UI was
     * right and the backend was wider than it, which is the drift a Policy
     * exists to prevent: one rule, asked by both.
     */
    public function test_the_ah_cannot_author_a_pre_dpc_through_any_entry_point(): void
    {
        $this->storePreDpc($this->ao, [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $this->postTo->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $this->postFrom->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],
            'government_order_number' => 'GO/1',
            'effective_from' => '2026-01-01',
        ]);

        $draft = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ah)->get(route('pre-dpc.edit', $draft))->assertForbidden();
        $this->actingAs($this->ah)->put(route('pre-dpc.update', $draft), [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $this->postTo->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $this->postFrom->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 9,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],
        ])->assertForbidden();

        // The draft is untouched — the refusal is not merely cosmetic.
        // The AH's attempted edit changed nothing: the draft still carries the
        // minimum experience the AO saved, not the 9 the AH tried to write.
        $this->assertSame(5, $draft->promotionSources()->sole()->min_experience_years);

        // The AO, whose draft it is, still can.
        $this->actingAs($this->ao)->get(route('pre-dpc.edit', $draft))->assertOk();
    }

    /** The Pre-DPC show screen offers each role only what the Policy allows it. */
    public function test_the_pre_dpc_screen_offers_each_role_only_its_own_actions(): void
    {
        $this->storePreDpc($this->ao, [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $this->postTo->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $this->postFrom->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],
            'government_order_number' => 'GO/1',
            'effective_from' => '2026-01-01',
        ]);

        $draft = PreDpcProposal::query()->latest('id')->firstOrFail();

        // DRAFT: the AO may edit, submit and cancel; the AH may do nothing yet.
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $draft))
            ->assertOk()
            ->assertSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertSee(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $draft->uuid]), escape: false);

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $draft))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertDontSee(route('esign.show', ['kind' => 'pre-dpc-approve', 'uuid' => $draft->uuid]), escape: false);

        // SUBMITTED: it flips — approval and return appear for the AH, and the
        // AO's authoring actions go away.
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $draft), ['declaration' => '1']);

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $draft->fresh()))
            ->assertOk()
            ->assertSee(route('esign.show', ['kind' => 'pre-dpc-approve', 'uuid' => $draft->uuid]), escape: false)
            ->assertSee(route('pre-dpc.return', $draft), escape: false);

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $draft->fresh()))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertDontSee(route('esign.show', ['kind' => 'pre-dpc-approve', 'uuid' => $draft->uuid]), escape: false);
    }

    // ------------------------------------------------------------------
    // DPC Proposal — creation is the Authorized Officer's (PPT S12)
    // ------------------------------------------------------------------

    public function test_the_ao_is_offered_a_new_dpc_proposal_and_a_review_dpc(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.index'))
            ->assertOk()
            ->assertSee(route('dpc-proposals.create'), escape: false)
            ->assertSee(route('dpc-proposals.reviews.create'), escape: false);
    }

    public function test_the_ah_is_offered_neither(): void
    {
        $this->actingAs($this->ah)->get(route('dpc-proposals.index'))
            ->assertOk()
            ->assertDontSee(route('dpc-proposals.create'), escape: false)
            ->assertDontSee(route('dpc-proposals.reviews.create'), escape: false);
    }

    public function test_the_ah_is_refused_dpc_creation_by_the_backend(): void
    {
        $this->actingAs($this->ah)->get(route('dpc-proposals.create'))->assertForbidden();
        $this->actingAs($this->ah)->get(route('dpc-proposals.reviews.create'))->assertForbidden();
    }

    /**
     * Review DPC (PPT S35–S37) has been built since M8 and, until now, nothing
     * in the UI linked to it. The route existing is not the same as the feature
     * being reachable.
     */
    public function test_the_review_dpc_screen_is_reachable_by_the_ao(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.reviews.create'))->assertOk();
    }

    // ------------------------------------------------------------------
    // Dashboard tiles
    // ------------------------------------------------------------------

    public function test_the_chairman_sees_the_allotments_tile(): void
    {
        $this->actingAs($this->chairman)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('cgpsc.allotments.index'), escape: false);
    }

    /**
     * Allotment is the Chairman's alone (PPT S28). A Member reaches the DPCs
     * allotted to them through Schedules, so the tile would only have led them
     * to a screen whose one action is hidden from them.
     */
    public function test_the_member_does_not_see_the_allotments_tile(): void
    {
        $response = $this->actingAs($this->member)->get(route('dashboard'))->assertOk();

        $response->assertDontSee(route('cgpsc.allotments.index'), escape: false);

        // The Member's own workspace is still offered.
        $response->assertSee(route('cgpsc.schedules.index'), escape: false);
        $response->assertSee(route('cgpsc.proceedings.index'), escape: false);
    }

    /**
     * **Which layer refuses this moved on 2026-08-26**, and the test moved with it.
     *
     * Item 9 gives allotment to the **DPC Chairman**, designated per DPC from that
     * DPC's committee — so a Member may legitimately allot *some* DPC, and a
     * class-level policy can no longer answer the question. `DpcAllotmentPolicy`
     * now says "a Commission actor could allot something" and
     * `AllotmentService::assertMayAllot()` says "not this one, and here is who may".
     *
     * So this asserts the outcome rather than the gate: a Member who has not been
     * designated cannot allot, whatever they post.
     */
    public function test_the_member_cannot_allot_a_dpc_they_were_not_designated_for(): void
    {
        // The screen is readable — a Member sees what they have been given.
        $this->actingAs($this->member)->get(route('cgpsc.allotments.index'))->assertOk();

        // A forwarded proposal for the Member to try to allot. Built here rather
        // than through the workflow: this test is about the refusal, and driving
        // the whole department-side flow would make it about something else.
        $forwarded = DpcProposal::query()->create([
            'department_id' => $this->postTo->department_id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'promotion_to_post_id' => $this->postTo->id,
            'promotion_from_post_id' => $this->postFrom->id,
            'proposal_date' => now()->toDateString(),
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'DPC-2026-RB-0001',
            'forwarded_at' => now(),
        ]);

        // Since the clarification of 2026-09-05 the Chairman names who presides as
        // part of allotting, and the field is required — so the payload has to be
        // complete for the request to reach the refusal this test is about.
        $this->seatCommittee($forwarded);

        $this->actingAs($this->member)->post(route('cgpsc.allotments.store', $forwarded), [
            'allotted_to' => $this->member->id,
            'dpc_chairman_member_id' => $this->presidingSeatId($forwarded),
            'complete_by' => now()->addMonths(2)->toDateString(),
        ])->assertSessionHasErrors('allotment');

        $this->assertSame(0, DpcAllotment::query()->count());
    }

    // ------------------------------------------------------------------
    // Department roles never see the Commission's workspace, and vice versa
    // ------------------------------------------------------------------

    public function test_a_department_officer_is_offered_no_commission_tiles(): void
    {
        $this->actingAs($this->ao)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('cgpsc.allotments.index'), escape: false)
            ->assertDontSee(route('cgpsc.proceedings.index'), escape: false)
            ->assertDontSee(route('admin.dashboard'), escape: false);
    }

    public function test_a_commission_member_is_offered_no_department_tiles(): void
    {
        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.index'), escape: false)
            ->assertDontSee(route('dpc-proposals.index'), escape: false)
            ->assertDontSee(route('admin.dashboard'), escape: false);
    }

    public function test_the_department_routes_refuse_a_commission_member(): void
    {
        $this->actingAs($this->member)->get(route('pre-dpc.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('dpc-proposals.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_the_commission_routes_refuse_a_department_officer(): void
    {
        $this->actingAs($this->ao)->get(route('cgpsc.allotments.index'))->assertForbidden();
        $this->actingAs($this->ao)->get(route('cgpsc.proceedings.index'))->assertForbidden();
    }
}
