<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\RecruitmentRuleSource;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\DepartmentPostSeeder;
use Database\Seeders\QualificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Master-data integrity on the Recruitment Rule: the promotion from post is a reference
 * to the Department Post master and the minimum qualification is a reference to the
 * Qualification master, and neither can be made to hold nonsense.
 *
 * These are validation and referential tests only. Nothing here touches how a
 * rule is *used* — no DPC workflow, no eligibility calculation, no business rule.
 */
class RecruitmentRuleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Masters are seeded for every test in this class, so a helper such as
     * target() may be called before admin() without ordering mattering.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            DepartmentMasterSeeder::class,
            DepartmentPostSeeder::class,
            QualificationSeeder::class,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    private function target(): DepartmentPost
    {
        return DepartmentPost::query()->where('name', 'Deputy Secretary')->firstOrFail();
    }

    private function feeder(): DepartmentPost
    {
        return DepartmentPost::query()->where('name', 'Under Secretary')->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $feeders
     * @return array<string, mixed>
     */
    private function payload(array $feeders, ?int $postId = null, string $promotionPct = '100'): array
    {
        // Default the unit on any row that does not state one, so each test only
        // has to spell out the field it is actually about.
        foreach ($feeders as $key => $feeder) {
            $feeders[$key]['min_experience_unit'] ??= ExperienceUnit::Years->value;
        }

        return [
            'post_id' => $postId ?? $this->target()->id,
            'government_order_number' => 'GO/2026/1',
            'direct_recruitment_pct' => '0',
            'promotion_recruitment_pct' => $promotionPct,
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Draft->value,
            'feeders' => $feeders,
        ];
    }

    // ------------------------------------------------------------------
    // The qualification is a reference, not a string
    // ------------------------------------------------------------------

    public function test_a_qualification_is_stored_as_a_foreign_key(): void
    {
        $admin = $this->admin();
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '5', 'qualification_ids' => [(string) $qualification->id]],
        ]))->assertRedirect(route('admin.recruitment-rules.index'));

        $feeder = RecruitmentRule::query()->where('post_id', $this->target()->id)
            ->firstOrFail()->promotionSources()->firstOrFail();

        $this->assertSame([$qualification->id], $feeder->qualifications->pluck('id')->all());
        $this->assertSame('Post-Graduate', $feeder->qualifications->sole()->name_en);
        // The legacy text column is kept in step so nothing that reads it regresses.
        $this->assertSame('Post-Graduate', $feeder->min_qualification);
        $this->assertSame('Post-Graduate', $feeder->qualificationLabel());
    }

    /**
     * A qualification that is withdrawn, deleted or never existed cannot be attached.
     *
     * @return array<string, array{0: string}>
     */
    public static function unattachableQualifications(): array
    {
        return [
            'an inactive qualification' => ['inactive'],
            'a soft-deleted qualification' => ['soft_deleted'],
            'a qualification that does not exist' => ['missing'],
        ];
    }

    #[DataProvider('unattachableQualifications')]
    public function test_an_unattachable_qualification_is_refused(string $state): void
    {
        $admin = $this->admin();
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        if ($state === 'inactive') {
            $qualification->forceFill(['status' => MasterStatus::Inactive->value])->save();
        }

        if ($state === 'soft_deleted') {
            $qualification->delete();
        }

        $id = $state === 'missing' ? 99999 : $qualification->id;

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '5', 'qualification_ids' => [(string) $id]],
        ]))->assertSessionHasErrors('feeders.0.qualification_ids.0');
    }

    public function test_a_withdrawn_qualification_still_displays_on_the_rules_that_cite_it(): void
    {
        $this->admin();
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $this->target()->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $feeder = $rule->promotionSources()->create([
            'promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => 100,
            'min_experience_years' => 5,

            // The legacy text column, which is what carries the record when the
            // master row is gone. Kept for exactly this case.
            'min_qualification' => 'Post-Graduate',
        ]);
        $feeder->qualifications()->attach($qualification->id, ['sort_order' => 0]);

        $qualification->delete();

        // The record of what the Government Order required does not disappear
        // because the qualification was later withdrawn from the master. The pivot
        // row goes with the soft delete's global scope, so the legacy text answers.
        $this->assertSame('Post-Graduate', $feeder->fresh()->qualificationLabel());
    }

    // ------------------------------------------------------------------
    // The promotion from post is a reference, and the set of them is sane
    // ------------------------------------------------------------------

    public function test_a_duplicate_promotion_from_post_is_refused(): void
    {
        $feeder = $this->feeder();

        $this->actingAs($this->admin())->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => '50', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => '50', 'min_experience_years' => '5'],
            // Shares total 100, so this can only fail on the duplicate.
        ]))->assertSessionHasErrors('feeders.0.promotion_from_post_id');
    }

    public function test_a_post_cannot_be_its_own_feeder(): void
    {
        $target = $this->target();

        $this->actingAs($this->admin())->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $target->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors('feeders.0.promotion_from_post_id');
    }

    public function test_a_promotion_from_post_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => 99999, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors('feeders.0.promotion_from_post_id');
    }

    public function test_a_feeder_from_another_department_is_refused(): void
    {
        $admin = $this->admin();
        $target = $this->target();

        $foreign = DepartmentPost::query()->create([
            'department_id' => $target->department_id + 1,
            'name' => 'Foreign Department Post',
            'class_level' => ServiceClass::II->value,
            'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $foreign->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors('feeders.0.promotion_from_post_id');
    }

    public function test_a_promotion_rule_must_name_at_least_one_feeder(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.recruitment-rules.store'), $this->payload([], promotionPct: '100'))
            ->assertSessionHasErrors('feeders');
    }

    /**
     * The one deliberate exception to "no empty feeder list": a post recruited
     * entirely by direct recruitment has no promotion from post by definition. The
     * seeded Teacher rule is exactly this, and forbidding it outright would make
     * an existing, valid record unsaveable.
     */
    public function test_a_direct_recruitment_only_rule_needs_no_feeder(): void
    {
        $this->actingAs($this->admin())->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '100'] + $this->payload([], promotionPct: '0'),
        )->assertRedirect(route('admin.recruitment-rules.index'));

        $rule = RecruitmentRule::query()->where('post_id', $this->target()->id)->firstOrFail();
        $this->assertSame(0, $rule->promotionSources()->count());
    }

    /**
     * A row added with the Add Promotion From Post button and then abandoned without
     * pressing Remove must not be treated as a feeder the user asked for.
     */
    public function test_a_blank_feeder_row_is_discarded(): void
    {
        $admin = $this->admin();
        $chosen = $this->feeder();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $chosen->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => '', 'promotion_percentage' => '', 'min_experience_years' => ''],
        ]))->assertRedirect(route('admin.recruitment-rules.index'));

        $rule = RecruitmentRule::query()->where('post_id', $this->target()->id)->firstOrFail();

        $this->assertSame(1, $rule->promotionSources()->count());
        $this->assertSame($chosen->id, $rule->promotionSources()->firstOrFail()->promotion_from_post_id);
    }

    public function test_a_new_version_carries_every_qualification(): void
    {
        $admin = $this->admin();
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $this->target()->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $second = Qualification::query()->where('code', '<>', 'PG')->firstOrFail();

        $source = $rule->promotionSources()->create([
            'promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => 100,
            'min_experience_years' => 5,
        ]);
        $source->qualifications()->attach([
            $qualification->id => ['sort_order' => 0],
            $second->id => ['sort_order' => 1],
        ]);

        $this->actingAs($admin)->post(route('admin.recruitment-rules.version', $rule))->assertRedirect();

        $v2 = RecruitmentRule::query()->where('post_id', $this->target()->id)->where('version', 2)->firstOrFail();

        // **Both** of them, in order. A pivot cannot be carried by `replicate()`,
        // so a new version that lost its qualification list would be a rule that
        // had quietly stopped requiring a degree.
        $this->assertSame(
            [$qualification->id, $second->id],
            $v2->promotionSources()->firstOrFail()->qualifications->pluck('id')->all(),
        );
    }

    // ------------------------------------------------------------------
    // DR % + PR % must account for the whole post
    //
    // Every sanctioned post is filled either directly or by promotion, so the
    // two shares describe the whole post. Distinct from the Promotion Share
    // rule below, which divides the promotion quota among promotion from posts.
    // ------------------------------------------------------------------

    public function test_dr_and_pr_that_do_not_total_100_are_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '30'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '50'),
        )->assertSessionHasErrors('recruitment_split');

        $this->assertSame(0, RecruitmentRule::query()->where('post_id', $this->target()->id)->count());
    }

    public function test_the_split_error_names_the_actual_total_and_the_shortfall(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '30'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '50'),
        )->assertSessionHasErrors([
            'recruitment_split' => 'Direct Recruitment % and Promotion % must total exactly 100%. They currently total 80% — 20% of the posts is unaccounted for.',
        ]);
    }

    public function test_dr_and_pr_over_100_are_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '60'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '60'),
        )->assertSessionHasErrors([
            'recruitment_split' => 'Direct Recruitment % and Promotion % must total exactly 100%. They currently total 120% — over-allocated by 20%.',
        ]);
    }

    /**
     * The two ends of the range the PPT actually shows: S9's every-row 0/100,
     * and the direct-recruitment-only post that has no feeder at all.
     */
    public function test_the_ppt_splits_are_accepted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '0'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '100'),
        )->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '100', 'post_id' => $this->feeder()->id]
                + $this->payload([], promotionPct: '0'),
        )->assertSessionHasNoErrors();

        $this->assertSame(2, RecruitmentRule::query()->count());
    }

    /** Halves and quarters, as the seeded Schedule-IV ladder uses. */
    public function test_a_split_other_than_zero_hundred_is_accepted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '25'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '75'),
        )->assertSessionHasNoErrors();
    }

    /**
     * Thirds must survive here too: the tolerance is the same hundredth of a
     * percent the Promotion Share total uses, so the two rules cannot disagree
     * about what "exactly 100" means.
     */
    public function test_a_split_totalling_100_by_rounding_is_accepted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(
            route('admin.recruitment-rules.store'),
            ['direct_recruitment_pct' => '33.33'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '66.67'),
        )->assertSessionHasNoErrors();
    }

    /**
     * A rule stored before this rule existed stays readable and listable.
     *
     * The check is on the write path only — the master is a record of what a
     * Government Order said, and rescaling one to satisfy a validation added
     * afterwards would be inventing policy.
     */
    public function test_an_existing_unbalanced_rule_is_still_readable(): void
    {
        $admin = $this->admin();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $this->target()->id, 'version' => 1, 'government_order_number' => 'GO/legacy',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 1,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Draft->value,
        ]);

        $this->actingAs($admin)->get(route('admin.recruitment-rules.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.recruitment-rules.edit', $rule))->assertOk();

        // Untouched on disk: nothing rewrites it behind the user's back.
        $this->assertSame('1.00', $rule->fresh()->promotion_recruitment_pct);

        // It is only on saving that the totals must balance — which is the
        // point: the user is told, and chooses what the correct figures are.
        $this->actingAs($admin)->put(
            route('admin.recruitment-rules.update', $rule),
            ['direct_recruitment_pct' => '0'] + $this->payload([
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100', 'min_experience_years' => '5'],
            ], promotionPct: '1'),
        )->assertSessionHasErrors('recruitment_split');
    }

    // ------------------------------------------------------------------
    // Promotion Share must account for the whole promotion quota
    // ------------------------------------------------------------------

    public function test_shares_that_do_not_total_100_are_refused(): void
    {
        $admin = $this->admin();
        $second = DepartmentPost::query()
            ->where('department_id', $this->target()->department_id)
            ->whereNotIn('id', [$this->feeder()->id, $this->target()->id])
            ->firstOrFail();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '40', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => $second->id, 'promotion_percentage' => '30', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors('feeders');

        $this->assertSame(0, RecruitmentRule::query()->where('post_id', $this->target()->id)->count());
    }

    public function test_the_error_names_the_actual_total_and_the_shortfall(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '60', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors(['feeders' => 'Promotion Share must total exactly 100% across all promotion from posts. It currently totals 60% — 40% is unallocated.']);
    }

    public function test_shares_over_100_are_refused(): void
    {
        $admin = $this->admin();
        $second = DepartmentPost::query()
            ->where('department_id', $this->target()->department_id)
            ->whereNotIn('id', [$this->feeder()->id, $this->target()->id])
            ->firstOrFail();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '70', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => $second->id, 'promotion_percentage' => '70', 'min_experience_years' => '5'],
        ]))->assertSessionHasErrors('feeders');
    }

    /** Thirds must be allowed: 33.33 + 33.33 + 33.34 is a legitimate split. */
    public function test_shares_totalling_100_by_rounding_are_accepted(): void
    {
        $admin = $this->admin();
        $others = DepartmentPost::query()
            ->where('department_id', $this->target()->department_id)
            ->whereNotIn('id', [$this->target()->id])
            ->take(3)->get();

        $this->assertCount(3, $others, 'This test needs three feeder-eligible posts.');

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $others[0]->id, 'promotion_percentage' => '33.33', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => $others[1]->id, 'promotion_percentage' => '33.33', 'min_experience_years' => '5'],
            ['promotion_from_post_id' => $others[2]->id, 'promotion_percentage' => '33.34', 'min_experience_years' => '5'],
        ]))->assertRedirect(route('admin.recruitment-rules.index'));

        $this->assertSame(3, RecruitmentRule::query()->where('post_id', $this->target()->id)
            ->firstOrFail()->promotionSources()->count());
    }

    // ------------------------------------------------------------------
    // Minimum experience: a value and a unit
    // ------------------------------------------------------------------

    public function test_experience_is_stored_with_its_unit(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '18', 'min_experience_unit' => ExperienceUnit::Months->value],
        ]))->assertRedirect(route('admin.recruitment-rules.index'));

        $feeder = RecruitmentRule::query()->where('post_id', $this->target()->id)
            ->firstOrFail()->promotionSources()->firstOrFail();

        $this->assertSame(18, $feeder->min_experience_years);
        $this->assertSame(ExperienceUnit::Months, $feeder->min_experience_unit);
        $this->assertSame('18 Months', $feeder->experienceLabel());
    }

    public function test_experience_defaults_to_years_for_callers_that_send_no_unit(): void
    {
        $admin = $this->admin();

        // No unit in the payload — the shape the Pre-DPC projection uses.
        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), [
            'post_id' => $this->target()->id,
            'government_order_number' => 'GO/2026/1',
            'direct_recruitment_pct' => '0',
            'promotion_recruitment_pct' => '100',
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Draft->value,
            'feeders' => [
                // No min_experience_unit at all — the pre-existing request shape.
                ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                    'min_experience_years' => '5'],
            ],
        ])->assertRedirect(route('admin.recruitment-rules.index'));

        $feeder = RecruitmentRule::query()->where('post_id', $this->target()->id)
            ->firstOrFail()->promotionSources()->firstOrFail();

        $this->assertSame(ExperienceUnit::Years, $feeder->min_experience_unit);
        $this->assertSame('5 Years', $feeder->experienceLabel());
    }

    public function test_a_negative_experience_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '-1'],
        ]))->assertSessionHasErrors('feeders.0.min_experience_years');
    }

    public function test_an_unknown_experience_unit_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '5', 'min_experience_unit' => 'fortnights'],
        ]))->assertSessionHasErrors('feeders.0.min_experience_unit');
    }

    public function test_zero_experience_shows_no_label_rather_than_zero_years(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.recruitment-rules.store'), $this->payload([
            ['promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => '100',
                'min_experience_years' => '0'],
        ]))->assertRedirect(route('admin.recruitment-rules.index'));

        $feeder = RecruitmentRule::query()->where('post_id', $this->target()->id)
            ->firstOrFail()->promotionSources()->firstOrFail();

        $this->assertNull($feeder->experienceLabel());
    }

    public function test_a_single_year_reads_as_singular(): void
    {
        $this->assertSame('1 Year', (new RecruitmentRuleSource([
            'min_experience_years' => 1, 'min_experience_unit' => ExperienceUnit::Years->value,
        ]))->experienceLabel());

        $this->assertSame('1 Month', (new RecruitmentRuleSource([
            'min_experience_years' => 1, 'min_experience_unit' => ExperienceUnit::Months->value,
        ]))->experienceLabel());
    }

    public function test_a_new_version_carries_the_experience_unit(): void
    {
        $admin = $this->admin();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $this->target()->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $rule->promotionSources()->create([
            'promotion_from_post_id' => $this->feeder()->id, 'promotion_percentage' => 100,
            'min_experience_years' => 30, 'min_experience_unit' => ExperienceUnit::Months->value,
        ]);

        $this->actingAs($admin)->post(route('admin.recruitment-rules.version', $rule))->assertRedirect();

        $v2 = RecruitmentRule::query()->where('post_id', $this->target()->id)->where('version', 2)->firstOrFail();

        $this->assertSame(ExperienceUnit::Months, $v2->promotionSources()->firstOrFail()->min_experience_unit);
    }
}
