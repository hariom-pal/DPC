<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * The Pre-DPC lifecycle as one promotion exercise (PPT S7–S11, reshaped by change
 * items 8–11 on 2026-08-24).
 *
 * The exercise-level rules — the DPC Year, the single active approved proposal per
 * exercise, the Pre-DPC Number, and item 5's auto-population — are asserted in
 * {@see PreDpcExerciseTest}. This file is the workflow: draft, submit, approve,
 * return, cancel, amend, and what approval projects.
 */
class PreDpcWorkflowTest extends TestCase
{
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

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();

        $scale = PayScale::query()->create([
            'code' => 'L12', 'label' => 'Level-12 (₹56,100)', 'status' => MasterStatus::Active->value,
        ]);

        /*
         | Both posts must exist before a proposal can name them, and that is the
         | order of operations the reshape introduced: a Pre-DPC no longer *mints*
         | a post on approval, it points at one the CGPSC Administrator registered
         | (change items 1 and 8-11).
         |
         | The two derived values are set here so the auto-population has something
         | real to derive: the Promotion To Post carries the pay scale and class,
         | the Promotion From Post carries the promotion duration.
         */
        $this->fromPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Under Secretary',
            'class_level' => 'I',
            'promotion_duration_years' => 5,
            'status' => MasterStatus::Active->value,
        ]);

        $this->toPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Deputy Secretary',
            'class_level' => 'I',
            'pay_scale_id' => $scale->id,
            'status' => MasterStatus::Active->value,
        ]);
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

            // One Promotion From Post taking the whole promotion quota. An order may
            // name several — `PreDpcExerciseTest` covers that — but a workflow test
            // wants the simplest order that is valid.
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $this->fromPost->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],

            'government_order_number' => 'GO/2026/HE/1',
            'effective_from' => '2026-01-01',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): PreDpcProposal
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload($overrides));

        return PreDpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * The blank form renders.
     *
     * A new proposal names no Promotion To Post, so item 5's derived line has
     * nothing to look up. It must read as an em dash rather than fail on a null
     * key — the officer opens this page before choosing anything at all.
     */
    public function test_the_blank_form_renders_before_a_post_is_chosen(): void
    {
        $this->actingAs($this->ao)->get(route('pre-dpc.create'))
            ->assertOk()
            ->assertSee(__('predpc.form.to_post_attributes', ['scale' => '—', 'class' => '—']));
    }

    // -------------------------------------------- deleting a draft (2026-09-06)

    /**
     * > *Give an option to delete a draft Pre-DPC. However we will always make it a
     * > soft delete, and only a draft Pre-DPC can be deleted.*
     *
     * Both halves are asserted, because the second is the one that can silently
     * stop holding: a hard delete looks identical from the screen.
     */
    public function test_the_officer_deletes_a_draft_and_the_row_is_kept(): void
    {
        $proposal = $this->draft();
        $number = $proposal->pre_dpc_number;

        $this->actingAs($this->ao)
            ->delete(route('pre-dpc.destroy', $proposal))
            ->assertRedirect(route('pre-dpc.index'));

        // Gone from every ordinary query...
        $this->assertNull(PreDpcProposal::query()->find($proposal->id));

        // ...and still on the record, with the number it drew from the department's
        // sequence. A number issued and then vanished would leave a hole in the
        // series that nobody could account for.
        $kept = PreDpcProposal::withTrashed()->findOrFail($proposal->id);

        $this->assertNotNull($kept->deleted_at);
        $this->assertSame($number, $kept->pre_dpc_number);

        // The feeders stay attached to it — they carry no `deleted_at` of their own,
        // so removing them would have been the hard delete the instruction rules out.
        $this->assertSame(1, $kept->promotionSources()->count());
    }

    public function test_the_deletion_is_recorded(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ao)->delete(route('pre-dpc.destroy', $proposal));

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'pre_dpc.deleted',
            'subject_id' => $proposal->id,
        ]);
    }

    /**
     * Everything past draft is cancelled, not deleted.
     *
     * A submitted proposal is with the Administrative Head; an approved one is a
     * Government Order. Neither is the officer's to discard.
     */
    public function test_only_a_draft_may_be_deleted(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ao)
            ->delete(route('pre-dpc.destroy', $proposal))
            ->assertForbidden();

        $this->assertNotNull(PreDpcProposal::query()->find($proposal->id));
    }

    public function test_the_administrative_head_cannot_delete_a_draft(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ah)
            ->delete(route('pre-dpc.destroy', $proposal))
            ->assertForbidden();

        $this->assertNotNull(PreDpcProposal::query()->find($proposal->id));
    }

    /**
     * The button is offered on a draft and on nothing else, so the screen agrees
     * with the policy rather than inviting a 403.
     */
    public function test_the_delete_button_appears_only_on_a_draft(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))
            ->assertOk()
            ->assertSee(__('predpc.show.delete_draft'));

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))
            ->assertOk()
            ->assertDontSee(__('predpc.show.delete_draft'));
    }

    // ---------------------------------- promotion goes up (business, 2026-09-06)

    /**
     * > *A Pre-DPC always goes from a lower grade to a higher grade, never the other
     * > way. The one exemption is Class-I to Class-I, and that only to a higher pay
     * > scale. Class-II to Class-I, Class-III to Class-II — but never Class-I to
     * > Class-II.*
     *
     * **The rule existed and was asked far too late.** `PromotionHierarchyService`
     * has judged post pairs since 2026-08-27, but only `DpcCalculationService`
     * consulted it — so a Class-I into Class-II exercise could be drafted, approved
     * by the Administrative Head, raised into a DPC and populated with a seniority
     * list before anything objected. Asked here, it is refused while somebody is
     * still choosing the feeder.
     *
     * The error is on the **feeder's own field**, because a Schedule-IV post is fed
     * from several and "one of your feeders is wrong" is not actionable with five
     * rows on screen.
     */
    public function test_a_feeder_above_the_promotion_post_is_refused(): void
    {
        $lower = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Section Officer',
            'class_level' => 'II',
            'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            // Class-I feeding Class-II: the pair the business named as never allowed.
            'promotion_to_post_id' => $lower->id,
        ]))->assertSessionHasErrors('promotion_sources.0.promotion_from_post_id');

        $this->assertSame(0, PreDpcProposal::query()->count());
    }

    public function test_a_feeder_below_the_promotion_post_is_accepted(): void
    {
        $lower = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Section Officer',
            'class_level' => 'II',
            'promotion_duration_years' => 5,
            'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            'promotion_sources' => [[
                'promotion_from_post_id' => $lower->id,
                'promotion_percentage' => 100,
                'min_experience_years' => 5,
                'experience_unit' => 'years',
                'qualification_ids' => $this->qualificationIds(),
            ]],
        ]))->assertSessionHasNoErrors();
    }

    /**
     * Within one class the pay scale has to do the promoting.
     *
     * Both directions are asserted from one setup, because the difference between
     * them is a single integer — and a rule that refused *both* would be
     * indistinguishable from one that refused neither if only the failing case were
     * pinned.
     */
    public function test_a_same_class_feeder_needs_a_lower_pay_scale_than_the_post_it_feeds(): void
    {
        $lowerScale = PayScale::query()->create([
            'code' => 'L11', 'label' => 'Level-11', 'level' => 11, 'status' => MasterStatus::Active->value,
        ]);
        $sameScale = PayScale::query()->create([
            'code' => 'L13', 'label' => 'Level-13', 'level' => 13, 'status' => MasterStatus::Active->value,
        ]);

        $this->toPost->forceFill(['pay_scale_id' => $sameScale->id])->save();

        // Same class, same scale — not a promotion at all.
        $this->fromPost->forceFill(['pay_scale_id' => $sameScale->id])->save();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload())
            ->assertSessionHasErrors('promotion_sources.0.promotion_from_post_id');

        // Same class, lower scale — the Class-I to Class-I exemption.
        $this->fromPost->forceFill(['pay_scale_id' => $lowerScale->id])->save();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload())
            ->assertSessionHasNoErrors();
    }

    /**
     * The summary states the class and pay scale of the post being promoted *into*
     * (business instruction, 2026-09-06).
     *
     * The card carried only the feeders' figures until then — correct for judging
     * eligibility, and confusing beside the Promotion To Post named two lines above
     * it, because the class and scale on display belonged to a different post.
     */
    public function test_the_summary_states_the_promotion_posts_class_and_pay_scale(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))
            ->assertOk()
            ->assertSee(__('predpc.show.class_of_promotion_post'))
            ->assertSee(__('predpc.show.pay_scale_of_promotion_post'))
            ->assertSee('Level-12 (₹56,100)');
    }

    /**
     * The qualification field is a searchable checkbox multiselect, and is still a
     * `<select multiple name="…[]">` underneath (business instruction, 2026-09-06).
     *
     * Both halves matter. `data-multiselect` is what
     * public/js/multiselect-dropdown.js enhances, so without it the officer is back
     * to ctrl-clicking a scrolling list; `multiple` and the `[]` name are what make
     * the posted payload an array, so a field that lost them would silently record
     * one qualification where the feeder accepts several.
     *
     * `data-searchable` is asserted *absent* on purpose: that is the single-value
     * enhancement, and it collapses a multiple select to one option.
     */
    public function test_the_qualification_field_is_a_searchable_checkbox_multiselect(): void
    {
        $response = $this->actingAs($this->ao)->get(route('pre-dpc.create'))->assertOk();

        $response->assertSee('data-multiselect', false);
        $response->assertSee('name="promotion_sources[__INDEX__][qualification_ids][]"', false);
        $response->assertSee('multiple', false);
        $response->assertDontSee('data-searchable-placeholder="', false);
    }

    /**
     * And once a post *is* chosen, the same line carries that post's own values —
     * server-rendered, so it is right with JavaScript disabled.
     */
    public function test_the_edit_form_shows_the_chosen_posts_derived_attributes(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ao)->get(route('pre-dpc.edit', $proposal))
            ->assertOk()
            ->assertSee(__('predpc.form.to_post_attributes', [
                'scale' => 'Level-12 (₹56,100)',
                'class' => ServiceClass::I->label(),
            ]));
    }

    public function test_ao_can_create_and_submit_a_draft(): void
    {
        $proposal = $this->draft();

        $this->assertSame(PreDpcStatus::Draft, $proposal->status);
        $this->assertSame($this->toPost->id, $proposal->promotion_to_post_id);
        $this->assertSame(2026, $proposal->dpc_year);

        // The feeder is a source row, because an order may name several.
        $this->assertSame(
            [$this->fromPost->id],
            $proposal->promotionSources->pluck('promotion_from_post_id')->all(),
        );

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->assertTrue(WorkflowTransition::query()->where('event', 'submit')->where('subject_id', $proposal->id)->exists());
        $this->assertDatabaseHas('activity_timelines', ['event' => 'pre_dpc.submit', 'subject_id' => $proposal->id]);
    }

    /**
     * Item 5: the derived values are stored from the posts, and are stored even
     * though the request never mentioned them.
     *
     * **Each from the post it describes.** The order's Pay Scale and Class describe
     * the post being *filled*. Until 2026-08-26 the committee member-level rule read
     * `class_level` as the class of
     * the promotion post (item 16), so they cannot mean anything else. The
     * qualifying service is the feeder's, because that is the post an officer
     * actually serves on.
     */
    public function test_the_pay_scale_class_and_promotion_duration_come_from_the_posts(): void
    {
        $proposal = $this->draft();

        $this->assertSame($this->toPost->pay_scale_id, $proposal->pay_scale_id, 'Pay Scale must come from the Promotion To Post.');
        $this->assertSame('I', $proposal->class_level?->value, 'Class must come from the Promotion To Post.');
        $this->assertSame(
            5,
            $proposal->promotionSources->firstOrFail()->promotion_duration_years,
            'Promotion Duration must come from the Promotion From Post.',
        );
    }

    /**
     * A crafted request cannot sanction terms the master does not hold.
     */
    public function test_derived_values_in_the_request_are_ignored(): void
    {
        $other = PayScale::query()->create(['code' => 'L14', 'label' => 'Level-14', 'status' => MasterStatus::Active->value]);

        $proposal = $this->draft([
            'pay_scale_id' => $other->id,
            'class_level' => 'III',
            'promotion_duration_years' => 99,
        ]);

        $this->assertSame($this->toPost->pay_scale_id, $proposal->pay_scale_id);
        $this->assertSame('I', $proposal->class_level?->value);
        $this->assertSame(5, $proposal->promotionSources->firstOrFail()->promotion_duration_years);
    }

    public function test_ah_approval_signs_activates_and_projects_the_recruitment_rule(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Approved, $proposal->status);
        $this->assertTrue($proposal->isActive());
        $this->assertNotNull($proposal->signature_id);

        $rule = RecruitmentRule::query()->where('post_id', $this->toPost->id)->sole();

        // The rule carries its feeder. Asserting only that a rule *exists* is what
        // let TD-25 hide: a rule with no feeder looks complete and sanctions
        // nobody.
        $feeder = $rule->promotionSources()->sole();
        $this->assertSame($this->fromPost->id, $feeder->promotion_from_post_id);
        $this->assertSame(5, $feeder->min_experience_years);
    }

    /**
     * **The regression this whole change exists to undo.**
     *
     * Schedule-IV fills most posts from several feeders — Deputy Secretary from
     * Under Secretary *and* Research Officer — and one Government Order names them
     * all. The reshape of 2026-08-24 collapsed a Pre-DPC to a single post pair,
     * which made this order impossible to record: the second feeder either
     * overwrote the first or was refused by the uniqueness rule.
     *
     * Asserted end to end, over HTTP through the real request and service, because
     * the loss was never in one layer — the form, the validation, the schema and the
     * projection each had to be able to carry a list.
     */
    public function test_one_order_may_name_several_promotion_from_posts(): void
    {
        $second = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Research Officer',
            'class_level' => 'I',
            'promotion_duration_years' => 7,
            'status' => MasterStatus::Active->value,
        ]);

        $proposal = $this->draft([
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $this->fromPost->id,
                    'promotion_percentage' => 60,
                    'min_experience_years' => 5,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
                [
                    'promotion_from_post_id' => $second->id,
                    'promotion_percentage' => 40,
                    'min_experience_years' => 7,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],
        ]);

        // Both survive the save, with their own terms — the qualifying service is
        // per feeder, so five years as an Under Secretary is not seven as a
        // Research Officer.
        $sources = $proposal->promotionSources->keyBy('promotion_from_post_id');
        $this->assertCount(2, $sources);
        $this->assertSame(5, $sources[$this->fromPost->id]->min_experience_years);
        $this->assertSame(7, $sources[$second->id]->min_experience_years);

        // And the qualifying service is read from each feeder's own mapping, not
        // from the post being filled.
        $this->assertSame(5, $sources[$this->fromPost->id]->promotion_duration_years);
        $this->assertSame(7, $sources[$second->id]->promotion_duration_years);

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        // The Recruitment Rule projected on approval names both, with the shares the
        // order sanctioned. A rule naming one feeder would silently disqualify every
        // Research Officer in the department.
        $rule = RecruitmentRule::query()->where('post_id', $this->toPost->id)->orderByDesc('version')->firstOrFail();

        $this->assertSame(2, $rule->promotionSources()->count());
        $this->assertEqualsCanonicalizing(
            [$this->fromPost->id, $second->id],
            $rule->promotionSources()->pluck('promotion_from_post_id')->all(),
        );
    }

    public function test_ah_can_return_a_submitted_proposal_with_remarks(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ah)->post(route('pre-dpc.return', $proposal), ['return_remarks' => 'Correct the GO number.'])
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Returned, $proposal->status);
        $this->assertSame('Correct the GO number.', $proposal->return_remarks);

        // Returned is editable again, and the number does not change.
        $number = $proposal->pre_dpc_number;
        $this->actingAs($this->ao)->get(route('pre-dpc.edit', $proposal))->assertOk();
        $this->assertSame($number, $proposal->fresh()->pre_dpc_number);
    }

    public function test_returning_without_remarks_is_refused(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        $this->actingAs($this->ah)->post(route('pre-dpc.return', $proposal), [])
            ->assertSessionHasErrors('return_remarks');

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
    }

    public function test_ao_can_cancel_a_draft(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ao)->post(route('pre-dpc.cancel', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal));

        $this->assertSame(PreDpcStatus::Cancelled, $proposal->fresh()->status);
    }

    public function test_amend_opens_a_new_version_of_the_same_exercise_with_its_own_number(): void
    {
        $approved = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $approved));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $approved));

        $this->actingAs($this->ao)->post(route('pre-dpc.amend', $approved->refresh()))->assertRedirect();

        $draft = PreDpcProposal::query()->latest('id')->firstOrFail();

        $this->assertSame(PreDpcStatus::Draft, $draft->status);
        $this->assertSame($approved->id, $draft->parent_version_id);
        $this->assertSame(2, $draft->version);

        // Same exercise…
        $this->assertSame($approved->exerciseKey(), $draft->exerciseKey());

        // …but its own Government Order, hence its own number, and not yet active.
        $this->assertNotSame($approved->pre_dpc_number, $draft->pre_dpc_number);
        $this->assertFalse($draft->isActive());
        $this->assertTrue($approved->refresh()->isActive(), 'The approved order stays in force until the amendment is approved.');
    }

    public function test_an_ah_cannot_author_and_an_ao_cannot_approve(): void
    {
        $proposal = $this->draft();

        $this->actingAs($this->ah)->get(route('pre-dpc.create'))->assertForbidden();

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ao)->post(route('pre-dpc.approve', $proposal))
            ->assertRedirect(route('pre-dpc.show', $proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
    }

    public function test_another_departments_officer_cannot_see_the_proposal(): void
    {
        $proposal = $this->draft();

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other, Role::AuthorizedOfficer)->create();

        $this->actingAs($intruder)->get(route('pre-dpc.show', $proposal))->assertForbidden();
    }

    public function test_submitting_without_a_government_order_is_refused_with_a_message_about_the_form(): void
    {
        $proposal = $this->draft(['government_order_number' => null, 'effective_from' => null]);

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
    }
}
