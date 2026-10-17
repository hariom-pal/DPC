<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Derived vacancy, the Zone of Consideration, and the gates on both (business
 * directive, 2026-08-22).
 *
 *   Working Officers = rows on the Currently Working chart
 *   Total Vacancy    = Sanctioned Post Count − Working Officers
 *   Zone             = the scoring engine's zone rule, applied to Total Vacancy
 *
 * The directive's own worked example — sanctioned 10, working 7, vacancy 3, zone
 * 10 — is the first test, because it is the number the business will check.
 *
 * **Two cases where the directive's flat `(V × 2) + 4` and the deck disagree are
 * pinned here deliberately**, so that a later decision to override the deck is a
 * visible change to these assertions rather than a silent drift:
 *
 *   - one vacancy sizes the zone at 5, not 6 ({@see test_a_single_vacancy_uses_the_decks_top_five_rule});
 *   - a class pair the deck gives no formula for has no cap, and therefore no
 *     gate ({@see test_a_class_pair_with_no_zone_formula_has_no_gate}).
 */
class DpcVacancyCalculationTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private VacancyType $clear;

    private VacancyType $retirement;

    private ReservationCategory $ur;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Manual entry is off by default since 2026-09-07 — the two grids are
         | upload-only — and the routes are gated on the same setting the screens
         | are. Switched on for this class, which exercises those routes directly.
         |
         | Kept as tests rather than deleted: the instruction was *"hide it, in case
         | we want it back"*, and a feature nobody tests is not one that can come
         | back.
         */
        config([
            'dpc.manual_entry.working_officers' => true,
            'dpc.manual_entry.seniority_list' => true,
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->clear = VacancyType::query()->where('code', 'clear')->firstOrFail();
        $this->retirement = VacancyType::query()->where('code', 'from_retirement')->firstOrFail();
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->proposal = $this->proposalFor('I', 'I');

        /*
         | Every proposal here gets a 100%-promotion rule.
         |
         | The Recruitment Rule became a **required input** with the business
         | directive of 2026-09-02: the promotion vacancy is now
         | `promotion chairs − promotion chairs held`, and without a rule there is
         | no way to say how many of the sanctioned chairs are promotion chairs.
         | These tests predate that and registered none, which used to be harmless
         | because the old arithmetic multiplied a finished total.
         |
         | 100% is the share that keeps every case below reading as it was written:
         | with no direct chairs, the promotion vacancy is the whole vacancy, which
         | is the number each of these tests reasons about. The split itself is
         | covered by the quota tests above.
         */
        $this->ruleFor(100.0);
    }

    // ------------------------------------------------------------------ helpers

    private function proposalFor(string $fromClass, string $toClass): DpcProposal
    {
        $from = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Feeder '.uniqid(),
            'class_level' => $fromClass, 'status' => 'active',
        ]);
        $to = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Target '.uniqid(),
            'class_level' => $toClass, 'status' => 'active',
        ]);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($from->id, $to->id)),
        );

        return DpcProposal::query()->latest('id')->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function officers(int $count): array
    {
        return array_map(fn (int $sn): array => [
            'sn' => $sn,
            'name' => 'DS'.$sn,
            'dob' => '1972-03-20',
            'reservation_category_id' => $this->ur->id,
        ], range(1, max(0, $count)) === [0] ? [] : range(1, $count));
    }

    /**
     * A chart of `$working` officers followed by `$vacant` empty chairs.
     *
     * **The vacancy is stated here since the business directive of 2026-09-02.**
     * It used to be a separate `vacancies` array whose counts had to be made to
     * add up to a total this screen calculated; a vacancy is now a chart row, and
     * the breakdown is derived from the chart rather than posted beside it.
     *
     * @return list<array<string, mixed>>
     */
    private function chart(int $working, int $vacant = 0): array
    {
        // `officers(0)` predates this helper and returns a bogus `sn` 0 row for a
        // zero count; a chart of nothing but empty chairs is a case it never saw.
        $rows = $working > 0 ? $this->officers($working) : [];

        for ($i = 0; $i < $vacant; $i++) {
            $rows[] = [
                'sn' => $working + $i + 1,
                'status' => DpcWorkingOfficer::STATUS_VACANT,
                'reservation_category_id' => $this->ur->id,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    /**
     * Save the screen, recording any `sanctioned_post_count` on the **master**.
     *
     * The change register of 2026-08-27 took that figure out of the form (item 1),
     * so a payload naming it is now describing the Department Post Mapping rather
     * than the submission. Translated here rather than at twenty call sites, which
     * keeps every test below reading as the arithmetic it is about.
     */
    private function save(array $payload, ?DpcProposal $proposal = null): TestResponse
    {
        $target = $proposal ?? $this->proposal;

        if (array_key_exists('sanctioned_post_count', $payload)) {
            $post = $target->promotionToPost;

            if ($post !== null) {
                $post->forceFill(['sanctioned_strength' => (int) $payload['sanctioned_post_count']])->save();
            }

            unset($payload['sanctioned_post_count']);
            $target->refresh();
        }

        return $this->actingAs($this->ao)->put(
            route('dpc-proposals.working-officers.update', $target),
            $payload,
        );
    }

    private function calculator(): VacancyCalculationService
    {
        return app(VacancyCalculationService::class);
    }

    // ------------------------------------------------ the directive's own example

    public function test_the_directives_worked_example(): void
    {
        // Sanctioned 10, 7 officers in post and 3 chairs empty → Working 7,
        // Total Vacancy 3, Zone 10.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());

        $this->assertSame(10, $summary['sanctioned']);
        $this->assertSame(7, $summary['working']);
        $this->assertSame(3, $summary['total_vacancy']);
        $this->assertSame(3, $summary['classified_total']);
        $this->assertTrue($summary['balanced']);
        $this->assertSame(10, $summary['required_seniority']);
        $this->assertSame('2 × 3 + 4', $summary['zone']->formula);
    }

    // ------------------------------------------------------- rule 4: the ceiling

    /**
     * The mandatory verification of 2026-09-06, end to end and in one test:
     *
     * create a Department Post through the Admin UI with a Sanctioned Strength →
     * edit it → confirm the database → run the Vacancy Calculation → confirm the
     * **updated** figure is the one the engine used. No seeder, no direct SQL.
     *
     * This is the assertion that says the Admin UI is now the maintenance source.
     * Before 2026-09-06 the first step alone was impossible: the form had no field
     * and the request had no rule, so the post saved with `NULL` and the engine
     * reported "not recorded" for ever.
     */
    public function test_the_admin_ui_is_the_maintenance_source_for_the_vacancy_engine(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        // 1–3. Create through the UI with a Sanctioned Strength, and save.
        $this->actingAs($admin)->post(route('admin.department-posts.store'), [
            'department_id' => $this->department->id,
            'name' => 'Establishment Officer',
            'class_level' => 'I',
            'promotion_duration_years' => 5,
            'sanctioned_strength' => 5,
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Active->value,
        ])->assertSessionHasNoErrors();

        $post = DepartmentPost::query()->where('name', 'Establishment Officer')->sole();
        $this->assertSame(5, $post->sanctioned_strength, 'Created through the UI, not by a seeder.');

        // 4–5. Edit, update, and confirm the database holds the new value.
        $this->actingAs($admin)->put(route('admin.department-posts.update', $post), [
            'department_id' => $this->department->id,
            'name' => 'Establishment Officer',
            'class_level' => 'I',
            'promotion_duration_years' => 5,
            'sanctioned_strength' => 9,
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Active->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9, $post->refresh()->sanctioned_strength);
        $this->assertDatabaseHas('department_posts', [
            'id' => $post->id,
            'sanctioned_strength' => 9,
        ]);

        // 6. The Vacancy Engine reads the **updated** figure from the mapping.
        $this->proposal->forceFill(['promotion_to_post_id' => $post->id])->save();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(9, $breakdown->sanctionedStrength, 'The engine must read the edited value, not the created one.');
    }

    public function test_working_officers_may_not_exceed_the_sanctioned_strength(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(12),
        ])->assertSessionHasErrors('officers');

        // Nothing was written: the refusal is not a warning.
        $this->assertSame(0, $this->proposal->fresh()->workingOfficers()->count());
    }

    public function test_working_officers_equal_to_the_sanctioned_strength_is_allowed(): void
    {
        // A fully-staffed post has no vacancy. Unusual for a DPC, not invalid —
        // refusing it would be inventing a rule the directive does not state.
        $this->save([
            'sanctioned_post_count' => 3,
            'officers' => $this->officers(3),
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());
        $this->assertSame(0, $summary['total_vacancy']);
        $this->assertFalse($summary['exceeds_sanctioned']);
    }

    // ------------------------- the quota subtraction (directive of 2026-09-02)

    /**
     * The register's own worked example, at both ends of the rule.
     *
     * Four sanctioned posts; one officer came by promotion and retires inside the
     * window, one came on deputation, one came by direct recruitment and stays,
     * and one chair is empty. Three chairs are therefore free either way — what
     * changes with the rule is **how many of them are this DPC's**.
     */
    public function test_the_promotion_vacancy_is_the_quota_subtraction(): void
    {
        $chart = [
            ['sn' => 1, 'status' => 'working', 'source' => 'promotion', 'name' => 'DS1', 'dob' => '1966-06-30',
                'reservation_category_id' => $this->ur->id, 'date_of_retirement' => $this->proposal->dpc_year.'-06-30'],
            ['sn' => 2, 'status' => 'working', 'source' => 'deputation', 'name' => 'DS2', 'dob' => '1970-08-25',
                'reservation_category_id' => $this->ur->id],
            ['sn' => 3, 'status' => 'working', 'source' => 'direct', 'name' => 'DS3', 'dob' => '1971-11-09',
                'reservation_category_id' => $this->ur->id],
            ['sn' => 4, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
        ];

        // 50/50 on four posts: two chairs each. Nobody holds a promotion chair
        // (the one promotion officer is leaving), so both promotion chairs are
        // vacant; one direct chair is genuinely held, so one direct chair is not.
        $this->ruleFor(50.0);
        $this->save(['sanctioned_post_count' => 4, 'officers' => $chart])->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(2, $breakdown->promotionChairs);
        $this->assertSame(2, $breakdown->directChairs);
        $this->assertSame(0, $breakdown->promotionChairsHeld);
        $this->assertSame(1, $breakdown->directChairsHeld);
        $this->assertSame(1, $breakdown->deputationChairsHeld);

        $this->assertSame(2, $breakdown->promotionVacancy, 'the DPC fills two');
        $this->assertSame(1, $breakdown->directRecruitmentVacancy, 'and Direct Recruitment one');
        $this->assertSame(3, $breakdown->totalVacancy);
    }

    /**
     * At 100% promotion every chair is a promotion chair, so every vacancy is the
     * DPC's — and a `direct` row becomes a contradiction rather than a share.
     */
    public function test_a_hundred_percent_promotion_post_has_no_direct_chair(): void
    {
        $this->ruleFor(100.0);

        $this->save([
            'sanctioned_post_count' => 4,
            'officers' => [
                ['sn' => 1, 'status' => 'working', 'source' => 'promotion', 'name' => 'DS1', 'dob' => '1971-11-09',
                    'reservation_category_id' => $this->ur->id],
                ['sn' => 2, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 3, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 4, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(4, $breakdown->promotionChairs);
        $this->assertSame(0, $breakdown->directChairs);
        $this->assertSame(3, $breakdown->promotionVacancy);
        $this->assertSame(0, $breakdown->directRecruitmentVacancy);
        $this->assertSame(3, $breakdown->totalVacancy);
        $this->assertFalse($breakdown->quotaOverfilled());
    }

    /**
     * A chart that contradicts the rule is reported, not silently absorbed.
     *
     * At 100% promotion there are no direct chairs, so an officer recorded as a
     * direct recruit describes an appointment the rule forbids. It surfaces as a
     * negative direct vacancy, which the screen states as a data fault.
     */
    public function test_a_direct_row_against_a_hundred_percent_promotion_post_is_a_fault(): void
    {
        $this->ruleFor(100.0);

        $this->save([
            'sanctioned_post_count' => 2,
            'officers' => [
                ['sn' => 1, 'status' => 'working', 'source' => 'direct', 'name' => 'DS1', 'dob' => '1971-11-09',
                    'reservation_category_id' => $this->ur->id],
                ['sn' => 2, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());

        $this->assertTrue($summary['quota_overfilled']);
        $this->assertSame(-1, $summary['direct_vacancy']);
    }

    /**
     * An officer who came on deputation holds the chair without consuming a
     * quota, so the chair stays this DPC's to fill.
     */
    public function test_a_deputation_officer_consumes_no_quota(): void
    {
        $this->ruleFor(100.0);

        $this->save([
            'sanctioned_post_count' => 2,
            'officers' => [
                ['sn' => 1, 'status' => 'working', 'source' => 'deputation', 'name' => 'DS1', 'dob' => '1971-11-09',
                    'reservation_category_id' => $this->ur->id],
                ['sn' => 2, 'status' => 'working', 'source' => 'promotion', 'name' => 'DS2', 'dob' => '1972-01-09',
                    'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(1, $breakdown->deputationChairsHeld);
        $this->assertSame(1, $breakdown->promotionChairsHeld);
        $this->assertSame(1, $breakdown->promotionVacancy);
    }

    /**
     * Only the promotion quota is stored, because the scoring engine fills seats
     * from these rows and a Direct Recruitment seat is not this committee's.
     */
    public function test_only_the_promotion_quota_is_stored_as_dpc_vacancy(): void
    {
        $this->ruleFor(50.0);

        $this->save([
            'sanctioned_post_count' => 4,
            'officers' => [
                ['sn' => 1, 'status' => 'working', 'source' => 'direct', 'name' => 'DS1', 'dob' => '1971-11-09',
                    'reservation_category_id' => $this->ur->id],
                ['sn' => 2, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 3, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 4, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        // 2 promotion chairs, none held -> 2 for the DPC. 2 direct chairs, one
        // held -> 1 for Direct Recruitment. Three chairs free in all.
        $this->assertSame(2, $breakdown->promotionVacancy);
        $this->assertSame(1, $breakdown->directRecruitmentVacancy);
        $this->assertSame(3, $breakdown->totalVacancy);

        $this->assertSame(2, (int) $this->proposal->fresh()->vacancies()->sum('count'));
        $this->assertTrue($this->calculator()->summary($this->proposal->fresh())['balanced']);
    }

    /**
     * The zone is sized on what the DPC fills, not on the whole establishment's
     * shortfall.
     */
    public function test_the_zone_is_sized_on_the_promotion_vacancy(): void
    {
        $this->ruleFor(50.0);

        $this->save([
            'sanctioned_post_count' => 4,
            'officers' => [
                ['sn' => 1, 'status' => 'working', 'source' => 'direct', 'name' => 'DS1', 'dob' => '1971-11-09',
                    'reservation_category_id' => $this->ur->id],
                ['sn' => 2, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 3, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
                ['sn' => 4, 'status' => 'vacant', 'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        // Promotion 2, total 3. `2 × 2 + 4 = 8` is the zone for two vacancies;
        // three would have given 10.
        $this->assertSame(8, $this->calculator()->requiredSeniorityCount($this->proposal->fresh()));
    }

    /**
     * A chart that lists fewer rows than the post is sanctioned for.
     *
     * The unlisted chairs still count as vacant — `sanctioned − held` does not
     * need a row — but nothing can be said about their reservation category, so
     * the section is reported incomplete rather than quietly classified short.
     */
    public function test_a_chart_that_misses_sanctioned_posts_is_incomplete(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(0, 4),
        ])->assertSessionHasNoErrors();

        $summary = $this->calculator()->summary($this->proposal->fresh());

        $this->assertTrue($summary['chart_incomplete']);
        $this->assertSame(10, $summary['total_vacancy'], 'the unlisted chairs are still vacant');
        $this->assertSame(4, $summary['classified_total'], 'but only the listed ones can be classified');

        $checklist = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'working_officers');

        $this->assertFalse($checklist['complete']);
        $this->assertStringContainsString('does not account for every sanctioned post', $checklist['detail']);
    }

    /**
     * Register a Recruitment Rule with the given promotion share.
     */
    private function ruleFor(float $percentage): void
    {
        // `forceDelete`, because the model soft-deletes and the unique
        // (post_id, version) index counts a trashed row.
        RecruitmentRule::query()->where('post_id', $this->proposal->promotion_to_post_id)->forceDelete();

        RecruitmentRule::query()->create([
            'post_id' => $this->proposal->promotion_to_post_id,
            'version' => 1,
            'government_order_number' => 'GO/QUOTA/1',
            'direct_recruitment_pct' => 100 - $percentage,
            'promotion_recruitment_pct' => $percentage,
            'effective_from' => '2020-01-01',
            'status' => MasterStatus::Active->value,
        ]);

        $this->proposal->forceFill(['recruitment_rule_id' => null])->save();
    }

    // ------------------------------- rule 6: the classification sum, now derived

    /**
     * **The invariant survived; the way it is kept changed.**
     *
     * The directive's invalid example — calculated 3, split totalling 4 — used to
     * be a refusal, because the split was typed. Since the business directive of
     * 2026-09-02 the breakdown is *derived from the chart*, so a disagreeing split
     * is not something a payload can express. This asserts the stronger property
     * the derivation buys: whatever the chart says, the two numbers agree.
     */
    public function test_the_breakdown_cannot_disagree_with_the_total(): void
    {
        // Each pair accounts for all ten sanctioned posts. A chart that lists
        // fewer is incomplete rather than unbalanced — covered separately below.
        foreach ([[7, 3], [5, 5], [10, 0], [0, 10]] as [$working, $vacant]) {
            $this->save([
                'sanctioned_post_count' => 10,
                'officers' => $this->chart($working, $vacant),
            ])->assertSessionHasNoErrors();

            $summary = $this->calculator()->summary($this->proposal->fresh());

            $this->assertSame(
                $summary['total_vacancy'],
                $summary['classified_total'],
                "chart of {$working} working and {$vacant} vacant should balance",
            );
            $this->assertTrue($summary['balanced']);
        }
    }

    /**
     * A posted `vacancies` array is ignored rather than trusted.
     *
     * The field is gone from the form, so this is about the wire: a crafted
     * payload must not be able to put a figure into the breakdown that the chart
     * does not evidence.
     */
    public function test_a_crafted_vacancies_payload_is_ignored(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
            'vacancies' => [
                ['vacancy_type_id' => $this->clear->id, 'reservation_category_id' => $this->ur->id, 'count' => 99],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, (int) $this->proposal->fresh()->vacancies()->sum('count'));
    }

    /**
     * The derived rows carry the ground each vacancy arose on, and the category it
     * falls to — which is what the reserved-category analysis reads.
     */
    public function test_the_derived_rows_name_their_ground_and_category(): void
    {
        $this->save([
            'sanctioned_post_count' => 4,
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id, 'date_of_retirement' => $this->proposal->dpc_year.'-06-30'],
                ['sn' => 2, 'name' => 'DS2', 'dob' => '1973-03-20', 'reservation_category_id' => $this->ur->id],
                ['sn' => 3, 'status' => DpcWorkingOfficer::STATUS_VACANT, 'reservation_category_id' => $this->ur->id],
                ['sn' => 4, 'status' => DpcWorkingOfficer::STATUS_VACANT, 'reservation_category_id' => $this->ur->id],
            ],
        ])->assertSessionHasNoErrors();

        $rows = $this->proposal->fresh()->vacancies()->with('vacancyType')->get()
            ->mapWithKeys(fn ($row): array => [$row->vacancyType->code => $row->count]);

        // The retiring officer came by promotion (the default), so his chair is
        // freed `from_promotion`; the two empty chairs are `clear`.
        $this->assertSame(2, $rows['clear']);
        $this->assertSame(1, $rows['from_promotion']);
        $this->assertSame(3, $this->calculator()->summary($this->proposal->fresh())['total_vacancy']);
    }

    public function test_a_zero_in_one_type_is_fine_as_long_as_the_total_matches(): void
    {
        // The directive's valid example, with Deputation at 0.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->calculator()->summary($this->proposal->fresh())['balanced']);
    }

    /**
     * **Why this invariant is worth more than it looks.**
     *
     * The scoring engine fills seats from the sum of the vacancy rows, not from
     * the sanctioned strength. Before this rule a department could record four
     * classified vacancies against a derived total of three, and the engine
     * would have promoted four officers. The invariant makes the number M5 acts
     * on equal the number the screen shows.
     */
    public function test_the_balanced_total_is_the_number_the_engine_will_use(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ])->assertSessionHasNoErrors();

        $proposal = $this->proposal->fresh();

        $this->assertSame(
            $this->calculator()->totalVacancy($proposal),
            (int) $proposal->vacancies()->sum('count'),
        );
    }

    // ------------------------------------------------------ the zone, and the deck

    public function test_the_zone_follows_the_vacancy(): void
    {
        foreach ([2 => 8, 3 => 10, 4 => 12, 10 => 24] as $vacancy => $expectedZone) {
            $this->assertSame(
                $expectedZone,
                $this->calculator()->requiredSeniorityCount($this->proposal, $vacancy),
                "vacancy {$vacancy} should size the zone at {$expectedZone}",
            );
        }
    }

    public function test_a_single_vacancy_uses_the_decks_top_five_rule(): void
    {
        // The directive says `(V × 2) + 4`, which would give 6. The deck's
        // sizing gives *top 5* for a single vacancy, and the deck governs
        // (AD-1) — so this is 5, and the divergence is deliberate and recorded.
        $this->assertSame(5, $this->calculator()->requiredSeniorityCount($this->proposal, 1));
    }

    public function test_no_vacancy_means_no_zone_and_no_gate(): void
    {
        $this->assertNull($this->calculator()->requiredSeniorityCount($this->proposal, 0));
    }

    public function test_a_class_pair_with_no_zone_formula_has_no_gate(): void
    {
        // The deck states a formula only for Class-I → Class-I. For every other
        // pair it says "other cases": each officer meeting the experience
        // qualification is considered, with no arithmetic cap. There is
        // therefore no exact count to require, and the gate cannot apply.
        $classTwo = $this->proposalFor('II', 'II');

        $zone = $this->calculator()->zone($classTwo, 3);

        $this->assertTrue($zone->isUncapped());
        $this->assertNull($this->calculator()->requiredSeniorityCount($classTwo, 3));
    }

    public function test_the_zone_is_the_engines_zone_and_not_a_second_implementation(): void
    {
        // AD-19: a rule is re-applied through the existing mechanism, never
        // through a second code path. Changing the configured multiplier must
        // move the proposal screen too — if it does not, there are two formulas.
        Config::set('dpc.scoring.zone.rules.I-I', [
            'single_vacancy_size' => 7, 'multiplier' => 3, 'addend' => 1,
        ]);

        $this->assertSame(10, $this->calculator()->requiredSeniorityCount($this->proposal, 3));
        $this->assertSame(7, $this->calculator()->requiredSeniorityCount($this->proposal, 1));
    }

    // ------------------------------------------- rule 3: the seniority-list gate

    /** @return list<array<string, mixed>> */
    private function seniority(int $count): array
    {
        return array_map(fn (int $sn): array => [
            'seniority_number' => $sn,
            'employee_number' => 'EMP-'.$sn,
            'name' => 'US'.$sn,
            'dob' => '1964-03-20',
            'date_of_joining_service' => '1988-07-01',
            'reservation_category_id' => $this->ur->id,
        ], range(1, $count));
    }

    /**
     * Arrange a proposal whose Total Vacancy is exactly `$vacancy`.
     *
     * Every sanctioned post is left vacant and no officer is in post, so there are
     * no retirements or deputations to add — Total Vacancy is the sanctioned
     * strength, which is what the zone tests below reason about.
     */
    private function withVacancyOf(int $vacancy): void
    {
        $post = $this->proposal->promotionToPost;

        if ($post !== null) {
            $post->forceFill(['sanctioned_strength' => $vacancy])->save();
        }

        $service = app(DpcProposalService::class);
        $service->saveWorkingOfficers($this->proposal->refresh(), $this->chart(0, $vacancy));
        $service->saveVacancies($this->proposal->refresh());
    }

    public function test_a_seniority_list_matching_the_zone_is_accepted(): void
    {
        $this->withVacancyOf(3); // zone 10

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $this->seniority(10)],
        )->assertSessionHasNoErrors();

        $this->assertSame(10, $this->proposal->fresh()->seniorityEntries()->count());
    }

    public function test_a_seniority_list_longer_than_the_zone_is_refused(): void
    {
        // The directive's example: zone 8, uploaded 10.
        $this->withVacancyOf(2); // zone 8

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $this->seniority(10)],
        )->assertSessionHasErrors([
            'entries' => 'The uploaded Seniority List must contain exactly 8 officers based on the calculated Zone of Consideration.',
        ]);

        $this->assertSame(0, $this->proposal->fresh()->seniorityEntries()->count());
    }

    public function test_a_seniority_list_shorter_than_the_zone_is_refused(): void
    {
        // The directive's example: zone 12, uploaded 9.
        $this->withVacancyOf(4); // zone 12

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $this->seniority(9)],
        )->assertSessionHasErrors([
            'entries' => 'The uploaded Seniority List must contain exactly 12 officers based on the calculated Zone of Consideration.',
        ]);
    }

    public function test_the_gate_is_silent_before_the_sanctioned_strength_is_known(): void
    {
        // No vacancy can be derived yet, so no count can be required. Refusing
        // here would block the officer from filling the sections in any order
        // but one — which the S14→S18 flow does not require.
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $this->seniority(3)],
        )->assertSessionHasNoErrors();
    }

    // ------------------------------------------------- rule 4: continuing is blocked

    public function test_an_unbalanced_proposal_cannot_be_submitted(): void
    {
        // Reached by editing sections in an order no single request validates:
        // save a chart that fits, then lower the sanctioned strength on its own.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ])->assertSessionHasNoErrors();

        // Supersede the strength *after* the chart was saved: the gate must judge
        // the mapping, not the snapshot the proposal is carrying.
        $this->proposal->promotionToPost->forceFill(['sanctioned_strength' => 5])->save();

        $missing = app(DpcProposalCompletenessService::class)->missingSections($this->proposal->fresh());

        $this->assertContains('Working Officers & Vacancies (S16)', $missing);
    }

    public function test_a_ceiling_breach_created_after_the_fact_blocks_submission(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ])->assertSessionHasNoErrors();

        // Sanctioned strength cut below the officer chart by a later Government
        // Order. The gate reads the mapping, so it notices.
        $this->proposal->promotionToPost->forceFill(['sanctioned_strength' => 4])->save();

        $checklist = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'working_officers');

        $this->assertFalse($checklist['complete']);
        $this->assertStringContainsString('cannot exceed the sanctioned strength', $checklist['detail']);
    }

    public function test_a_seniority_list_that_misses_the_zone_blocks_submission(): void
    {
        $this->withVacancyOf(3); // zone 10
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, $this->seniority(4), $this->ao);

        $checklist = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->fresh()))
            ->firstWhere('key', 'seniority_list');

        $this->assertFalse($checklist['complete']);
        $this->assertStringContainsString('exactly 10 officers', $checklist['detail']);
    }

    // --------------------------------------------------------- standing them down

    public function test_each_gate_can_be_stood_down_independently(): void
    {
        Config::set('dpc.vacancy.enforce_sanctioned_ceiling', false);
        Config::set('dpc.vacancy.enforce_classification_balance', false);

        // A 12-row chart against a sanctioned 10.
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->officers(12),
        ])->assertSessionHasNoErrors();

        Config::set('dpc.vacancy.enforce_seniority_matches_zone', false);

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $this->seniority(2)],
        )->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_screen_shows_the_three_derived_values_read_only(): void
    {
        $this->save([
            'sanctioned_post_count' => 10,
            'officers' => $this->chart(7, 3),
        ]);

        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk();

        $response->assertSee('Working Officers');
        $response->assertSee('Total Vacancy');
        $response->assertSee('Zone of Consideration');

        /*
         | Every vacancy figure is *stated*, never editable.
         |
         | Stronger than the read-only inputs this used to assert: since the change
         | register of 2026-08-27 the nine summary figures are table cells rather
         | than disabled fields, so there is no control to type over and nothing to
         | post. The zone remains a read-only input beside them.
         |
         | Asserting the **absence** of the old input is what pins item 1: a
         | sanctioned-strength box reappearing on this screen is the regression that
         | would put the figure back in the officer's hands.
         */
        $response->assertSee('id="vacancy-summary"', escape: false);
        $response->assertSee('id="derived_zone"', escape: false);
        $response->assertDontSee('name="sanctioned_post_count"', escape: false);

        /*
         | One readonly control, not three.
         |
         | This asserted three when Working Officers, Total Vacancy and the Zone were
         | each a disabled input. Two of them are now table cells — a stronger
         | guarantee, because a cell cannot be typed over at all and posts nothing —
         | so the zone is the only readonly field left. Kept as an exact count for
         | the reason it always was: a fourth readonly control appearing here would
         | mean a derived figure had been turned back into a form field.
         */
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), 'readonly'),
            'the zone is the only readonly control; the vacancy figures are stated, not fielded',
        );
    }

    public function test_the_seniority_screen_states_the_required_count(): void
    {
        $this->withVacancyOf(3);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('Zone of Consideration')
            ->assertSee('exactly 10');
    }
}
