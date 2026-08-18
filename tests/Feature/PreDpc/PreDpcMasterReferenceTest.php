<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\QualificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Pre-DPC screen selects its post, feeder cadre and minimum qualification
 * from the masters (business instruction, 2026-08-18).
 *
 * Two things are being pinned here, and the second matters as much as the first:
 *
 *  1. a reference chosen on the screen is **stored as a reference** and carried
 *     through approval into the Recruitment Rule master without being re-derived
 *     from text;
 *  2. a caller that posts only the **old text shape** — the demo seeder, the
 *     pre-existing `PreDpcWorkflowTest`, any API client written before today —
 *     still works exactly as it did.
 *
 * Nothing here touches eligibility, scoring or any DPC workflow.
 */
class PreDpcMasterReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private Cadre $target;

    private Cadre $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(QualificationSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);
        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $this->target = $this->cadre('Deputy Secretary', 'I');
        $this->feeder = $this->cadre('Under Secretary', 'I');
    }

    private function cadre(string $name, string $class, ?Department $department = null): Cadre
    {
        return Cadre::query()->create([
            'department_id' => ($department ?? $this->department)->id,
            'name' => $name,
            'class_level' => $class,
            'status' => MasterStatus::Active->value,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $feeders
     * @param  array<string, mixed>  $postOverrides
     * @return array<string, mixed>
     */
    private function payload(array $feeders, array $postOverrides = []): array
    {
        return [
            'government_order_number' => 'GO/2026/HE/1',
            'effective_from' => '2026-01-01',
            'posts' => [array_merge([
                'cadre_id' => $this->target->id,
                'class_level' => 'I',
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                'feeders' => $feeders,
            ], $postOverrides)],
        ];
    }

    // ------------------------------------------------------------------
    // References are stored as references
    // ------------------------------------------------------------------

    public function test_the_post_and_feeder_are_stored_as_cadre_references(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            ['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5],
        ]))->assertSessionHasNoErrors();

        $post = PreDpcProposal::query()->latest('id')->firstOrFail()->posts()->firstOrFail();

        $this->assertSame($this->target->id, $post->cadre_id);
        $this->assertSame($this->feeder->id, $post->feeders()->firstOrFail()->feeder_cadre_id);
    }

    /**
     * The screen posts no typed name at all; the request fills both in from the
     * chosen cadres. Everything that reads `post_name` keeps working.
     */
    public function test_the_names_are_derived_from_the_chosen_cadres(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            ['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5],
        ]))->assertSessionHasNoErrors();

        $post = PreDpcProposal::query()->latest('id')->firstOrFail()->posts()->firstOrFail();

        $this->assertSame('Deputy Secretary', $post->post_name);
        $this->assertSame('Under Secretary', $post->feeders()->firstOrFail()->feeder_name);
    }

    public function test_the_qualification_is_stored_as_a_foreign_key(): void
    {
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            [
                'feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'qualification_id' => $qualification->id,
            ],
        ]))->assertSessionHasNoErrors();

        $feeder = PreDpcProposal::query()->latest('id')->firstOrFail()->posts()->firstOrFail()->feeders()->firstOrFail();

        $this->assertSame($qualification->id, $feeder->qualification_id);
        $this->assertSame('Post-Graduate', $feeder->qualificationLabel());
    }

    // ------------------------------------------------------------------
    // The references survive approval into the Recruitment Rule master
    // ------------------------------------------------------------------

    public function test_approval_projects_the_references_without_creating_a_duplicate_cadre(): void
    {
        Storage::fake('local');
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();
        $cadresBefore = Cadre::query()->count();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            [
                'feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'qualification_id' => $qualification->id,
            ],
        ]))->assertSessionHasNoErrors();

        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        // The post was already a master row, so projection must not mint another.
        $this->assertSame($cadresBefore, Cadre::query()->count());

        $rule = RecruitmentRule::query()->where('cadre_id', $this->target->id)->firstOrFail();
        $ruleFeeder = $rule->feeders()->firstOrFail();

        $this->assertSame($this->feeder->id, $ruleFeeder->feeder_cadre_id);
        $this->assertSame($qualification->id, $ruleFeeder->qualification_id);
    }

    /**
     * An amendment starts from what was approved. Dropping the references would
     * quietly downgrade a referenced proposal to a text one — and it would look
     * fine, because the names travel with it.
     */
    public function test_an_amendment_carries_the_references_forward(): void
    {
        Storage::fake('local');
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            [
                'feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'qualification_id' => $qualification->id,
            ],
        ]));

        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));
        $this->actingAs($this->ao)->post(route('pre-dpc.amend', $proposal->refresh()));

        $v2 = PreDpcProposal::query()->where('version', 2)->firstOrFail();
        $post = $v2->posts()->firstOrFail();

        $this->assertSame($this->target->id, $post->cadre_id);
        $this->assertSame($this->feeder->id, $post->feeders()->firstOrFail()->feeder_cadre_id);
        $this->assertSame($qualification->id, $post->feeders()->firstOrFail()->qualification_id);
    }

    // ------------------------------------------------------------------
    // Backward compatibility — the text shape still works
    // ------------------------------------------------------------------

    /**
     * The shape every caller used before 2026-08-18: names, no ids. It must
     * behave exactly as it did — including creating the cadre from the name,
     * which is how a department's master got populated in the first place.
     */
    public function test_a_text_only_payload_still_works_end_to_end(): void
    {
        Storage::fake('local');

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/2026/HE/2',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'post_name' => 'Section Officer',
                'class_level' => 'II',
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                // Named, not referenced. It resolves against an existing cadre
                // by name at projection — which is the only way a text-shaped
                // feeder has ever reached the master.
                'feeders' => [[
                    'feeder_name' => 'Under Secretary',
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                    'min_qualification' => 'Post-Graduate',
                ]],
            ]],
        ])->assertSessionHasNoErrors();

        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();
        $post = $proposal->posts()->firstOrFail();

        // Stored as text, with no reference — resolution still happens later.
        $this->assertNull($post->cadre_id);
        $this->assertSame('Section Officer', $post->post_name);
        $this->assertNull($post->feeders()->firstOrFail()->qualification_id);

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        // Approval creates the cadres from the names, exactly as before...
        $created = Cadre::query()->where('department_id', $this->department->id)->where('name', 'Section Officer')->firstOrFail();

        // ...resolves the feeder name against the existing cadre...
        $rule = RecruitmentRule::query()->where('cadre_id', $created->id)->firstOrFail();
        $ruleFeeder = $rule->feeders()->firstOrFail();
        $this->assertSame($this->feeder->id, $ruleFeeder->feeder_cadre_id);

        // ...and resolves the qualification text against the master, as before.
        $this->assertSame(
            Qualification::query()->where('code', 'PG')->value('id'),
            $ruleFeeder->qualification_id,
        );
    }

    /** A caller that sends both keeps its own name; the master does not overrule it. */
    public function test_a_supplied_name_is_not_overwritten_by_the_cadre(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload(
            [['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]],
            ['post_name' => 'Deputy Secretary (Establishment)'],
        ))->assertSessionHasNoErrors();

        $this->assertSame(
            'Deputy Secretary (Establishment)',
            PreDpcProposal::query()->latest('id')->firstOrFail()->posts()->firstOrFail()->post_name,
        );
    }

    // ------------------------------------------------------------------
    // What the master reference makes it possible to refuse
    // ------------------------------------------------------------------

    public function test_a_cadre_from_another_department_is_refused(): void
    {
        $other = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $foreign = $this->cadre('Teacher', 'III', $other);

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload(
            [['feeder_cadre_id' => $foreign->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]],
        ))->assertSessionHasErrors('posts.0.feeders.0.feeder_cadre_id');

        $this->assertSame(0, PreDpcProposal::query()->count());
    }

    public function test_a_post_cannot_be_its_own_feeder(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload(
            [['feeder_cadre_id' => $this->target->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]],
        ))->assertSessionHasErrors('posts.0.feeders.0.feeder_cadre_id');
    }

    /**
     * The Recruitment Rule master carries a unique index on (rule, feeder
     * cadre). Without this check the duplicate would survive until projection
     * and then fail on the database — inside the approval transaction, after
     * the proposal had been digitally signed.
     */
    public function test_a_duplicate_feeder_cadre_is_refused(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            ['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 50, 'min_experience_years' => 5],
            ['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 50, 'min_experience_years' => 5],
        ]))->assertSessionHasErrors('posts.0.feeders.1.feeder_cadre_id');
    }

    public function test_the_same_post_cannot_be_listed_twice(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/2026/HE/1',
            'effective_from' => '2026-01-01',
            'posts' => [
                ['cadre_id' => $this->target->id, 'class_level' => 'I', 'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
                    'feeders' => [['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]]],
                ['cadre_id' => $this->target->id, 'class_level' => 'I', 'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
                    'feeders' => [['feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5]]],
            ],
        ])->assertSessionHasErrors('posts.1.cadre_id');
    }

    public function test_a_withdrawn_qualification_is_refused(): void
    {
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();
        $qualification->update(['status' => MasterStatus::Inactive->value]);

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            [
                'feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'qualification_id' => $qualification->id,
            ],
        ]))->assertSessionHasErrors('posts.0.feeders.0.qualification_id');
    }

    /**
     * `Rule::exists` runs on the query builder, so the SoftDeletes global scope
     * never applies — a trashed row's `status` column still reads 'active'.
     * Without the explicit `whereNull('deleted_at')` this would validate.
     */
    public function test_a_soft_deleted_qualification_is_refused(): void
    {
        $qualification = Qualification::query()->where('code', 'PG')->firstOrFail();
        $qualification->delete();

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), $this->payload([
            [
                'feeder_cadre_id' => $this->feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'qualification_id' => $qualification->id,
            ],
        ]))->assertSessionHasErrors('posts.0.feeders.0.qualification_id');
    }

    // ------------------------------------------------------------------
    // The screen
    // ------------------------------------------------------------------

    public function test_the_form_offers_the_masters_and_no_free_text(): void
    {
        $response = $this->actingAs($this->ao)->get(route('pre-dpc.create'))->assertOk();

        $response->assertSee('name="posts[__P__][cadre_id]"', escape: false);
        $response->assertSee('name="posts[__P__][feeders][__F__][feeder_cadre_id]"', escape: false);
        $response->assertSee('name="posts[__P__][feeders][__F__][qualification_id]"', escape: false);

        // The three free-text inputs the screen used to carry are gone.
        $response->assertDontSee('name="posts[__P__][post_name]"', escape: false);
        $response->assertDontSee('name="posts[__P__][feeders][__F__][feeder_name]"', escape: false);
        $response->assertDontSee('name="posts[__P__][feeders][__F__][min_qualification]"', escape: false);
    }

    public function test_every_feeder_column_is_labelled(): void
    {
        $response = $this->actingAs($this->ao)->get(route('pre-dpc.create'))->assertOk();

        foreach (['Feeder Cadre', 'Promotion Share (%)', 'Minimum Experience', 'Minimum Qualification', 'Action'] as $label) {
            $response->assertSee($label, escape: false);
        }
    }

    /**
     * A department whose Cadre master is empty cannot fill this form in at all,
     * because every identifying field is now a reference into it. The screen has
     * to say so — and must not present a form that cannot be completed.
     */
    public function test_a_department_with_no_cadres_is_told_what_is_missing(): void
    {
        Cadre::query()->where('department_id', $this->department->id)->delete();

        $this->actingAs($this->ao)->get(route('pre-dpc.create'))
            ->assertOk()
            ->assertSee('No post is registered for your department yet.')
            ->assertSee('CGPSC Administration');
    }
}
