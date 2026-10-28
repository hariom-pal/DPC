<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\OpensPreDpcs;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * The exercise-level rules of change items 5, 8, 9 and 10.
 *
 *   - item 8: every Pre-DPC carries a DPC Year;
 *   - item 9: only one *active approved* Pre-DPC per department + To Post + DPC
 *     Year, the previous one becoming inactive rather than deleted. The From Post
 *     left the key when several were restored: one order sanctions promotion into
 *     one post and lists every post it may draw on;
 *   - item 10: a unique Pre-DPC Number of the form `PDPC-2026-HED-0001`;
 *   - item 5: the Pay Scale, Class and Promotion Duration come from the posts.
 */
class PreDpcExerciseTest extends TestCase
{
    use OpensPreDpcs;
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DepartmentPost $fromPost;

    private DepartmentPost $toPost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::query()->create(['code' => 'HED', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();

        $scale = PayScale::query()->create(['code' => 'L12', 'label' => 'Level-12', 'status' => MasterStatus::Active->value]);

        $this->fromPost = $this->departmentPost('Under Secretary', ['promotion_duration_years' => 5]);
        $this->toPost = $this->departmentPost('Deputy Secretary', ['pay_scale_id' => $scale->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function departmentPost(string $name, array $attributes = [], ?Department $department = null): DepartmentPost
    {
        return DepartmentPost::query()->create(array_replace([
            'department_id' => ($department ?? $this->department)->id,
            'name' => $name,
            'class_level' => 'I',
            'status' => MasterStatus::Active->value,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'dpc_year' => 2026,
            'promotion_to_post_id' => $this->toPost->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [$this->source()],
            'government_order_number' => 'GO/2026/HED/1',
            'effective_from' => '2026-01-01',
        ], $overrides);
    }

    /**
     * One Promotion From Post, taking the whole promotion quota by default.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function source(array $overrides = []): array
    {
        return array_replace([
            'promotion_from_post_id' => $this->fromPost->id,
            'promotion_percentage' => 100,
            'min_experience_years' => 5,
            'experience_unit' => 'years',
            'qualification_ids' => $this->qualificationIds(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function approved(array $overrides = []): PreDpcProposal
    {
        $this->storePreDpc($this->ao, $this->payload($overrides))->assertRedirect();
        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        return $proposal->refresh();
    }

    // ------------------------------------------------------------------
    // Item 8 — DPC Year
    // ------------------------------------------------------------------

    public function test_a_proposal_without_a_dpc_year_is_refused(): void
    {
        $this->storePreDpc($this->ao, $this->payload(['dpc_year' => null]))
            ->assertSessionHasErrors('dpc_year');

        $this->assertSame(0, PreDpcProposal::query()->count());
    }

    public function test_a_dpc_year_beyond_next_year_is_refused(): void
    {
        $this->storePreDpc($this->ao, $this->payload(['dpc_year' => (int) now()->addYears(3)->format('Y')]))
            ->assertSessionHasErrors('dpc_year');
    }

    public function test_a_dpc_year_before_2019_is_refused(): void
    {
        // Coder Instructions rule 1.4: reservation in promotion ended in 2019.
        $this->storePreDpc($this->ao, $this->payload(['dpc_year' => 2018]))
            ->assertSessionHasErrors(['dpc_year' => __('predpc.validation.year_before_earliest', ['year' => 2019])]);

        $this->assertSame(0, PreDpcProposal::query()->count());
    }

    // ------------------------------------------------------------------
    // Item 10 — the Pre-DPC Number
    // ------------------------------------------------------------------

    public function test_the_number_is_issued_at_draft_in_the_prescribed_form(): void
    {
        $this->storePreDpc($this->ao, $this->payload());

        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->assertSame('PDPC-2026-HED-0001', $proposal->pre_dpc_number);
    }

    public function test_numbers_run_per_department_per_year(): void
    {
        $this->storePreDpc($this->ao, $this->payload());

        $second = $this->departmentPost('Joint Secretary', ['promotion_duration_years' => 3]);
        $this->storePreDpc($this->ao, $this->payload(['promotion_to_post_id' => $second->id]));

        $this->storePreDpc($this->ao, $this->payload(['dpc_year' => 2025]));

        $numbers = PreDpcProposal::query()->orderBy('id')->pluck('pre_dpc_number')->all();

        $this->assertSame(['PDPC-2026-HED-0001', 'PDPC-2026-HED-0002', 'PDPC-2025-HED-0001'], $numbers);
    }

    public function test_the_database_refuses_a_duplicate_number(): void
    {
        $first = $this->approved();

        $this->expectException(QueryException::class);

        PreDpcProposal::query()->create([
            'pre_dpc_number' => $first->pre_dpc_number,
            'department_id' => $this->department->id,
            'dpc_year' => 2026,
            'version' => 99,
            'status' => 'draft',
        ]);
    }

    // ------------------------------------------------------------------
    // Item 9 — one active approved Pre-DPC per exercise
    // ------------------------------------------------------------------

    public function test_approving_an_amendment_deactivates_the_previous_order_without_deleting_it(): void
    {
        $first = $this->approved();
        $this->assertTrue($first->isActive());

        $this->actingAs($this->ao)->post(route('pre-dpc.amend', $first));
        $amendment = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $amendment));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $amendment));

        $first->refresh();
        $amendment->refresh();

        $this->assertFalse($first->isActive(), 'The superseded order must stop being active.');
        $this->assertTrue($amendment->isActive());

        // Never deleted (item 9, in terms), and it keeps everything that made it
        // an order: its number, its approval and its signature.
        $this->assertDatabaseHas('pre_dpc_proposals', ['id' => $first->id, 'status' => 'approved']);
        $this->assertNotNull($first->signature_id);
        $this->assertNotNull($first->approved_at);
    }

    public function test_the_database_refuses_two_active_proposals_for_one_exercise(): void
    {
        $first = $this->approved();

        $this->expectException(QueryException::class);

        // Deliberately bypassing the service: the guarantee has to hold against
        // code that does not go through it, which is why it is a unique index and
        // not a check in a method.
        //
        // `forceCreate` because `active_flag` is not mass-assignable — which is
        // itself deliberate, and is a second reason no request can activate an
        // order. Here it is forced precisely to prove the index and not the model
        // is what refuses.
        PreDpcProposal::query()->forceCreate([
            'pre_dpc_number' => 'PDPC-2026-HED-9999',
            'department_id' => $this->department->id,
            'dpc_year' => $first->dpc_year,
            'promotion_to_post_id' => $first->promotion_to_post_id,
            'version' => 99,
            'status' => 'approved',
            'active_flag' => 1,
        ]);
    }

    public function test_a_different_year_is_a_different_exercise_and_may_also_be_active(): void
    {
        $twentySix = $this->approved();
        $twentyFive = $this->approved(['dpc_year' => 2025]);

        $this->assertTrue($twentySix->refresh()->isActive());
        $this->assertTrue($twentyFive->refresh()->isActive());
    }

    public function test_a_different_promotion_to_post_is_a_different_exercise_and_may_also_be_active(): void
    {
        $first = $this->approved();

        $other = $this->departmentPost('Joint Secretary', ['promotion_duration_years' => 3]);
        $second = $this->approved(['promotion_to_post_id' => $other->id]);

        $this->assertTrue($first->refresh()->isActive());
        $this->assertTrue($second->refresh()->isActive());
    }

    public function test_a_fresh_draft_for_an_already_active_exercise_is_refused_with_an_explanation(): void
    {
        $active = $this->approved();

        $this->storePreDpc($this->ao, $this->payload())
            ->assertSessionHasErrors('promotion_to_post_id');

        $this->assertSame(
            1,
            PreDpcProposal::query()->where('promotion_to_post_id', $this->toPost->id)->count(),
            'The refusal must stop the row being written, not merely warn about it.',
        );
        $this->assertTrue($active->refresh()->isActive());
    }

    // ------------------------------------------------------------------
    // The pair itself
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Several Promotion From Posts — restored 2026-08-25
    // ------------------------------------------------------------------

    /**
     * The shares must divide the promotion quota exactly.
     *
     * Not a new rule: it is the one the Recruitment Rule master already enforces,
     * and it exists because the engine fills promotion seats from these shares. A
     * set totalling 80% leaves a fifth of the promotion vacancies sanctioned by
     * nothing at all.
     */
    public function test_sources_whose_shares_do_not_total_one_hundred_are_refused(): void
    {
        $second = $this->departmentPost('Research Officer', ['promotion_duration_years' => 7]);

        $this->storePreDpc($this->ao, $this->payload([
            'promotion_sources' => [
                $this->source(['promotion_percentage' => 60]),
                $this->source(['promotion_from_post_id' => $second->id, 'promotion_percentage' => 20]),
            ],
        ]))
            ->assertSessionHasErrors('promotion_sources');

        // The Department Admin's draft stands; the refused form saved nothing on it.
        $this->assertSame(0, PreDpcProposal::query()->sole()->promotionSources()->count());
    }

    /**
     * Naming a post twice would carry two shares and two qualifying periods for the
     * same officers.
     */
    public function test_the_same_post_cannot_be_named_twice(): void
    {
        $this->storePreDpc($this->ao, $this->payload([
            'promotion_sources' => [
                $this->source(['promotion_percentage' => 50]),
                $this->source(['promotion_percentage' => 50]),
            ],
        ]))
            ->assertSessionHasErrors('promotion_sources.1.promotion_from_post_id');
    }

    /**
     * The sentences written for these refusals are the ones the officer reads.
     *
     * **Asserted because they stopped firing once and nothing failed.** The custom
     * messages were keyed on a bare `promotion_from_post_id`, which matches nothing
     * now that the field is `promotion_sources.0.promotion_from_post_id` — the rules
     * still refused the save, but with the framework's generic wording. That is the
     * kind of regression a passing suite hides: the screen quietly gets worse and no
     * assertion is looking at it.
     */
    public function test_the_refusals_use_the_wording_written_for_them(): void
    {
        $errors = fn (array $payload): ViewErrorBag => $this->storePreDpc($this->ao, $payload)
            ->assertSessionHasErrors()
            ->getSession()
            ->get('errors');

        $noPost = $this->payload(['promotion_sources' => [
            ['promotion_percentage' => 100, 'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds()],
        ]]);

        $this->assertSame(
            'Choose the Promotion From Post — the post officers are promoted out of.',
            $errors($noPost)->first('promotion_sources.0.promotion_from_post_id'),
        );

        $itself = $this->payload(['promotion_to_post_id' => $this->fromPost->id]);

        $this->assertSame(
            'A post cannot be promoted into itself. Choose a different Promotion From Post.',
            $errors($itself)->first('promotion_sources.0.promotion_from_post_id'),
        );

        $this->assertSame(
            'Name at least one Promotion From Post. An order that names none sanctions nobody\'s promotion.',
            $errors($this->payload(['promotion_sources' => []]))->first('promotion_sources'),
        );
    }

    /**
     * **Issue 1, asserted where it is decided.** Pay Scale and Class are read from
     * the post being described, so two feeders with different mappings produce two
     * different answers — which is the whole reason the figure belongs beside each
     * feeder rather than once at the top of the form.
     */
    public function test_each_source_keeps_the_terms_of_its_own_post(): void
    {
        $otherScale = PayScale::query()->create(['code' => 'L11', 'label' => 'Level-11', 'status' => MasterStatus::Active->value]);
        $second = $this->departmentPost('Research Officer', [
            'class_level' => 'II',
            'pay_scale_id' => $otherScale->id,
            'promotion_duration_years' => 7,
        ]);

        $this->storePreDpc($this->ao, $this->payload([
            'promotion_sources' => [
                $this->source(['promotion_percentage' => 60]),
                $this->source([
                    'promotion_from_post_id' => $second->id,
                    'promotion_percentage' => 40,
                    'min_experience_years' => 7,
                ]),
            ],
        ]))
            ->assertRedirect();

        $sources = PreDpcProposal::query()->latest('id')->firstOrFail()
            ->promotionSources()->with('promotionFromPost')->get()->keyBy('promotion_from_post_id');

        $this->assertCount(2, $sources);

        // The qualifying service is copied off each feeder's own mapping.
        $this->assertSame(5, $sources[$this->fromPost->id]->promotion_duration_years);
        $this->assertSame(7, $sources[$second->id]->promotion_duration_years);

        // And each feeder's Pay Scale and Class remain its own — Under Secretary is
        // Class I on no scale, Research Officer is Class II on Level-11.
        $this->assertSame('I', $sources[$this->fromPost->id]->promotionFromPost->class_level->value);
        $this->assertSame('II', $sources[$second->id]->promotionFromPost->class_level->value);
        $this->assertSame($otherScale->id, $sources[$second->id]->promotionFromPost->pay_scale_id);
    }

    /**
     * **Issue 2, asserted at the boundary the regression crossed.** One department,
     * one Promotion To Post, one year — and two feeders. Before the restoration the
     * second was refused by the uniqueness rule; it must now be a second row on the
     * same order.
     */
    public function test_two_feeders_into_one_post_are_one_order_not_two(): void
    {
        $second = $this->departmentPost('Research Officer', ['promotion_duration_years' => 7]);

        $order = $this->approved([
            'promotion_sources' => [
                $this->source(['promotion_percentage' => 60]),
                $this->source(['promotion_from_post_id' => $second->id, 'promotion_percentage' => 40]),
            ],
        ]);

        $this->assertSame(1, PreDpcProposal::query()->count());
        $this->assertTrue($order->isActive());
        $this->assertEqualsCanonicalizing(
            [$this->fromPost->id, $second->id],
            $order->promotionSources()->pluck('promotion_from_post_id')->all(),
        );
    }

    public function test_a_post_from_another_department_is_refused(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $foreign = $this->departmentPost('Executive Engineer', [], $other);

        $this->storePreDpc($this->ao, $this->payload([
            'promotion_sources' => [$this->source(['promotion_from_post_id' => $foreign->id])],
        ]))
            ->assertSessionHasErrors('promotion_sources.0.promotion_from_post_id');
    }

    public function test_a_draft_post_is_refused_because_nothing_has_sanctioned_it(): void
    {
        $draftPost = $this->departmentPost('Special Secretary', ['status' => MasterStatus::Draft->value]);

        $this->storePreDpc($this->ao, $this->payload(['promotion_to_post_id' => $draftPost->id]))
            ->assertSessionHasErrors('promotion_to_post_id');
    }

    public function test_a_recruitment_split_that_does_not_total_one_hundred_is_refused(): void
    {
        $this->storePreDpc($this->ao, $this->payload([
            'direct_recruitment_pct' => 30,
            'promotion_recruitment_pct' => 50,
        ]))
            ->assertSessionHasErrors('promotion_recruitment_pct');
    }

    /**
     * Whole numbers only since 2026-10-28: a split of whole percentages is saved,
     * a decimal one is refused.
     */
    public function test_a_whole_number_split_is_saved_and_a_decimal_one_refused(): void
    {
        $this->storePreDpc($this->ao, $this->payload([
            'direct_recruitment_pct' => '33',
            'promotion_recruitment_pct' => '67',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, PreDpcProposal::query()->count());

        $this->actingAs($this->ao)->put(route('pre-dpc.update', PreDpcProposal::query()->sole()), $this->payload([
            'direct_recruitment_pct' => '33.33',
            'promotion_recruitment_pct' => '66.67',
        ]))->assertSessionHasErrors(['direct_recruitment_pct', 'promotion_recruitment_pct']);
    }

    // ------------------------------------------------------------------
    // Item 5 — the auto-population endpoint
    // ------------------------------------------------------------------

    /**
     * The endpoint answers **per post**, and each post answers for itself.
     *
     * It used to answer for a pair, reading the Pay Scale and Class off the To post
     * and the qualifying service off the From post. That put the promotion post's
     * scale beside a feeder — a figure describing a post nobody had been promoted
     * into yet — which is the display half of the 2026-08-25 regression.
     */
    public function test_the_endpoint_answers_for_each_post_from_its_own_mapping(): void
    {
        $this->actingAs($this->ao)
            ->getJson(route('pre-dpc.post-attributes', [
                'post_ids' => [$this->fromPost->id, $this->toPost->id],
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'posts')
            ->assertJsonFragment([
                'id' => $this->toPost->id,
                'pay_scale_label' => 'Level-12',
                'class_level' => 'I',
                'promotion_duration_years' => null,
            ])
            ->assertJsonFragment([
                'id' => $this->fromPost->id,

                // Under Secretary carries no scale of its own, and the answer says
                // so rather than borrowing the promotion post's.
                'pay_scale_label' => null,
                'class_level' => 'I',
                'promotion_duration_years' => 5,
            ]);
    }

    public function test_the_endpoint_accepts_a_single_post(): void
    {
        $this->actingAs($this->ao)
            ->getJson(route('pre-dpc.post-attributes', ['post_id' => $this->toPost->id]))
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.pay_scale_label', 'Level-12');
    }

    public function test_the_endpoint_answers_nothing_for_another_departments_post(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $foreign = $this->departmentPost('Executive Engineer', ['pay_scale_id' => null], $other);

        $this->actingAs($this->ao)
            ->getJson(route('pre-dpc.post-attributes', ['post_ids' => [$foreign->id]]))
            ->assertOk()
            ->assertJsonCount(0, 'posts');
    }

    public function test_the_endpoint_is_closed_to_a_commission_account(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Role::Member->value);

        $this->actingAs($member)
            ->getJson(route('pre-dpc.post-attributes', ['post_ids' => [$this->toPost->id]]))
            ->assertForbidden();
    }
}
