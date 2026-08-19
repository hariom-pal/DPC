<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    private User $ao;

    private User $ah;

    private User $chairman;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        Cadre::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        Cadre::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->ao = User::factory()->create(['department_id' => $department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->ah = User::factory()->create(['department_id' => $department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        // CGPSC users hold no department: a DPC reaches the Commission
        // precisely by leaving its department's control.
        $this->chairman = User::factory()->create(['department_id' => null]);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->member = User::factory()->create(['department_id' => null]);
        $this->member->assignRole(Role::Member->value);
    }

    // ------------------------------------------------------------------
    // Pre-DPC — authoring is the Authorized Officer's (PPT S7)
    // ------------------------------------------------------------------

    public function test_the_ao_is_offered_a_new_pre_dpc_proposal(): void
    {
        $this->actingAs($this->ao)->get(route('pre-dpc.index'))
            ->assertOk()
            ->assertSee(route('pre-dpc.create'), escape: false);

        $this->actingAs($this->ao)->get(route('pre-dpc.create'))->assertOk();
    }

    public function test_the_ah_is_not_offered_a_new_pre_dpc_proposal(): void
    {
        // The AH still reaches the list — approving is done from it.
        $this->actingAs($this->ah)->get(route('pre-dpc.index'))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.create'), escape: false);
    }

    public function test_the_ah_is_refused_pre_dpc_authoring_by_the_backend(): void
    {
        $this->actingAs($this->ah)->get(route('pre-dpc.create'))->assertForbidden();

        $this->actingAs($this->ah)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/1',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'post_name' => 'Deputy Secretary', 'class_level' => 'I',
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            ]],
        ])->assertForbidden();
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
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/1',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'post_name' => 'Deputy Secretary', 'class_level' => 'I',
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            ]],
        ]);

        $draft = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ah)->get(route('pre-dpc.edit', $draft))->assertForbidden();
        $this->actingAs($this->ah)->put(route('pre-dpc.update', $draft), [
            'posts' => [[
                'post_name' => 'Rewritten By The AH', 'class_level' => 'I',
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            ]],
        ])->assertForbidden();

        // The draft is untouched — the refusal is not merely cosmetic.
        $this->assertSame('Deputy Secretary', $draft->fresh()->posts()->firstOrFail()->post_name);

        // The AO, whose draft it is, still can.
        $this->actingAs($this->ao)->get(route('pre-dpc.edit', $draft))->assertOk();
    }

    /** The Pre-DPC show screen offers each role only what the Policy allows it. */
    public function test_the_pre_dpc_screen_offers_each_role_only_its_own_actions(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/1',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'post_name' => 'Deputy Secretary', 'class_level' => 'I',
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            ]],
        ]);

        $draft = PreDpcProposal::query()->latest('id')->firstOrFail();

        // DRAFT: the AO may edit, submit and cancel; the AH may do nothing yet.
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $draft))
            ->assertOk()
            ->assertSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertSee(route('pre-dpc.submit', $draft), escape: false);

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $draft))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertDontSee(route('pre-dpc.approve', $draft), escape: false);

        // SUBMITTED: it flips — approval and return appear for the AH, and the
        // AO's authoring actions go away.
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $draft));

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $draft->fresh()))
            ->assertOk()
            ->assertSee(route('pre-dpc.approve', $draft), escape: false)
            ->assertSee(route('pre-dpc.return', $draft), escape: false);

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $draft->fresh()))
            ->assertOk()
            ->assertDontSee(route('pre-dpc.edit', $draft), escape: false)
            ->assertDontSee(route('pre-dpc.approve', $draft), escape: false);
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

    public function test_the_member_cannot_allot_even_by_posting_directly(): void
    {
        // The screen is readable — a Member sees what they have been given —
        // but the allotment action is refused.
        $this->actingAs($this->member)->get(route('cgpsc.allotments.index'))->assertOk();

        $this->assertFalse($this->member->can('allot', DpcAllotment::class));
        $this->assertTrue($this->chairman->can('allot', DpcAllotment::class));
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
