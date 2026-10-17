<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcVacancyCalculation;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationRecorder;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

class DpcWorkingOfficersTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private VacancyType $clearVacancy;

    private ReservationCategory $ur;

    private DepartmentPost $postTo;

    private RecruitmentRule $rule;

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

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);

        /*
         | The Sanctioned Strength is recorded in the Vacancy Master for the DPC
         | year (2026-10-17) — the AO does not type it — so it is recorded below,
         | once the proposal exists.
         */
        $postTo = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->postTo = $postTo;

        /*
         | Item 6: the promotion percentage comes **only** from the Recruitment Rule,
         | so a fixture that wants a promotion/direct split has to register one.
         |
         | `RaisesDpcProposals` builds its approved Pre-DPC directly rather than
         | through `PreDpcProposalService`, so the projection that would normally
         | create this rule never runs — see the note in that trait for why it
         | bypasses the workflow.
         |
         | 60% promotion is deliberately *not* a clean divisor of the fixture's three
         | vacancies: 3 × 60% = 1.8, which is what makes the rounding rule visible.
         */
        $this->rule = RecruitmentRule::query()->create([
            'post_id' => $postTo->id,
            'version' => 1,
            'government_order_number' => 'GO/VAC/1',
            'direct_recruitment_pct' => 40,
            'promotion_recruitment_pct' => 60,
            'effective_from' => '2020-01-01',
            'status' => MasterStatus::Active->value,
        ]);

        $this->clearVacancy = VacancyType::query()->where('code', 'clear')->firstOrFail();
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
        $this->recordSanctionedStrength($this->proposal, 4);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        /*
         | The mapping is sanctioned for 4: one officer in post and three chairs
         | empty, so Total Vacancy is 3 and the derived breakdown is three Clear
         | vacancies in UR.
         |
         | The vacancy used to be a `vacancies` array posted beside the chart and
         | made to add up to 3. Since the business directive of 2026-09-02 it is
         | stated on the chart itself, so it is here.
         */
        return array_merge([
            'officers' => array_merge(
                [['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id]],
                $this->vacant(3, 2),
            ),
        ], $overrides);
    }

    /**
     * `$count` vacant chart rows, numbered from `$firstSn`.
     *
     * @return list<array<string, mixed>>
     */
    private function vacant(int $count, int $firstSn): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'sn' => $firstSn + $i,
                'status' => DpcWorkingOfficer::STATUS_VACANT,
                'reservation_category_id' => $this->ur->id,
            ];
        }

        return $rows;
    }

    /**
     * Manual entry is off by default, and the route is closed with it (business
     * instruction, 2026-09-07).
     *
     * > *Remove manual entry of Working Officers and the Seniority List. For now
     * > make it available only through upload — hide it, in case we want it back.*
     *
     * **Asserted with the setting at its default**, which this class otherwise turns
     * on. Hiding a form and leaving its route open removes the button rather than
     * the feature, and this route accepts a whole officer chart.
     */
    public function test_manual_entry_is_closed_when_the_setting_is_off(): void
    {
        config(['dpc.manual_entry.working_officers' => false]);

        $this->actingAs($this->ao)
            ->put(route('dpc-proposals.working-officers.update', $this->proposal), ['officers' => []])
            ->assertForbidden();
    }

    /**
     * The screen offers the upload and nothing to type into.
     */
    public function test_the_screen_hides_the_grid_when_manual_entry_is_off(): void
    {
        config(['dpc.manual_entry.working_officers' => false]);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk()
            ->assertSee(__('dpc.working_officers.manual_entry_off'))
            ->assertDontSee('id="add-officer"', false)
            // The upload is the way in, and it is still there.
            ->assertSee(__('dpc.imports.upload.upload_from_excel'));
    }

    public function test_ao_can_save_vacancies_and_working_officers(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload())
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();

        // Written from the master as a snapshot, not received from the form.
        $this->assertSame(4, $this->proposal->sanctioned_post_count);

        /*
         | One derived breakdown row, and it carries **one** seat rather than three.
         |
         | The rule here is 60% promotion, so four sanctioned posts divide into two
         | promotion chairs and two direct. One promotion chair is held by the
         | officer on the chart, so the DPC has one seat — the other two of the
         | three free chairs fall to Direct Recruitment. Only the promotion quota is
         | stored, because those are the only seats this committee may fill.
         */
        $this->assertSame(1, $this->proposal->vacancies()->count());
        $this->assertSame(1, (int) $this->proposal->vacancies()->sum('count'));

        // The chart holds every sanctioned post — one officer and three empty
        // chairs — and only the first of them is a person.
        $this->assertSame(4, $this->proposal->workingOfficers()->count());
        $this->assertSame(1, $this->proposal->workingOfficers()->where('status', 'working')->count());
        $this->assertSame('DS1', $this->proposal->workingOfficers()->where('status', 'working')->firstOrFail()->name);
    }

    public function test_working_officer_stores_the_full_standard_employee_snapshot(): void
    {
        // Architect directive, 2026-08-13: every proposal snapshot table
        // carries the same ten officer fields.
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [[
                'sn' => 1,
                'employee_number' => 'EMP-77',
                'name' => 'DS1',
                'dob' => '1972-03-20',
                'qualification' => 'M.A.',
                'promotion_to_post' => 'Deputy Secretary',
                'promotion_from_post' => 'Under Secretary',
                'reservation_category_id' => $this->ur->id,
                'date_of_joining_service' => '1995-04-01',
                'date_of_posting_on_promotion_to_post' => '2019-07-15',
                'date_of_posting_on_promotion_from_post' => '2012-01-10',
            ]],
        ]));

        $officer = $this->proposal->workingOfficers()->firstOrFail();

        $this->assertSame('EMP-77', $officer->employee_number);
        $this->assertSame('M.A.', $officer->qualification);
        $this->assertSame('Deputy Secretary', $officer->promotion_to_post);
        $this->assertSame('Under Secretary', $officer->promotion_from_post);
        $this->assertSame($this->ur->id, $officer->reservation_category_id);
        $this->assertSame('1995-04-01', $officer->date_of_joining_service?->format('Y-m-d'));
        $this->assertSame('2019-07-15', $officer->date_of_posting_on_promotion_to_post?->format('Y-m-d'));
        $this->assertSame('2012-01-10', $officer->date_of_posting_on_promotion_from_post?->format('Y-m-d'));
    }

    /**
     * The breakdown is derived, so a posted one is ignored rather than validated.
     *
     * These two used to assert that a bad vacancy type was refused and that a
     * duplicate type/category pair was refused. Neither is reachable now: the rows
     * are produced by `VacancyEngine::classification()` from real master ids, one
     * row per pair by construction. What is worth pinning is that the wire cannot
     * introduce either fault.
     */
    public function test_a_posted_vacancy_breakdown_is_ignored(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'vacancies' => [
                ['vacancy_type_id' => 999999, 'reservation_category_id' => $this->ur->id, 'count' => 1],
                ['vacancy_type_id' => $this->clearVacancy->id, 'reservation_category_id' => $this->ur->id, 'count' => 99],
            ],
        ]))->assertSessionHasNoErrors();

        $rows = $this->proposal->fresh()->vacancies;

        $this->assertCount(1, $rows);
        $this->assertSame($this->clearVacancy->id, $rows->first()->vacancy_type_id);

        // One promotion seat at 60% — see the note in the save test above.
        $this->assertSame(1, $rows->first()->count);
    }

    public function test_the_derived_breakdown_has_one_row_per_type_and_category_pair(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => array_merge([
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => $year.'-06-30'],
            ], $this->vacant(3, 2)),
        ]))->assertSessionHasNoErrors();

        $pairs = $this->proposal->fresh()->vacancies
            ->map(fn ($row): string => $row->vacancy_type_id.'-'.$row->reservation_category_id);

        $this->assertSame($pairs->unique()->count(), $pairs->count());

        // Two promotion chairs at 60%, neither held once the officer retires.
        $this->assertSame(2, (int) $this->proposal->fresh()->vacancies()->sum('count'));
    }

    /**
     * Item 1: the AO does not enter vacancy figures, and cannot.
     *
     * The field is not merely read-only on the screen — it is absent from the form
     * and ignored on the wire, so a crafted payload cannot set it either.
     */
    public function test_the_sanctioned_strength_cannot_be_set_from_the_form(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.working-officers.update', $this->proposal),
            $this->payload(['sanctioned_post_count' => 999]),
        )->assertSessionHasNoErrors();

        // The master's figure, not the posted one.
        $this->assertSame(4, $this->proposal->refresh()->sanctioned_post_count);
    }

    /**
     * Item 3: *"If an officer occupying the Promotion Post retires between
     * 1 January and 30 November of the DPC Year, automatically increase one
     * additional vacancy."*
     */
    public function test_a_retirement_inside_the_period_adds_a_vacancy(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => $year.'-06-30'],
                // Three chairs empty, plus the one this officer is about to leave.
                ...$this->vacant(3, 2),
            ],
        ]))->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(3, $breakdown->baseVacancy);
        $this->assertSame(1, $breakdown->retirementVacancy);
        $this->assertSame(4, $breakdown->totalVacancy);
    }

    /**
     * Two officers retiring in the window free two posts. The register's sentence is
     * about *an* officer, so the count is per officer rather than a flat one.
     */
    public function test_each_retiring_officer_adds_its_own_vacancy(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => $year.'-03-31'],
                ['sn' => 2, 'name' => 'DS2', 'dob' => '1971-01-10', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => $year.'-09-30'],
                ...$this->vacant(2, 3),
            ],
        ]))->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(2, $breakdown->retirementVacancy);
        $this->assertSame(4, $breakdown->totalVacancy);
    }

    /**
     * Item 9: a retirement outside the window is refused, and the message says to
     * leave the field blank — such a retirement adds no vacancy to this DPC Year,
     * so a blank records exactly as much. **BD-35.**
     *
     * Since 2026-10-15 the window runs to 31 December (rule 1.5), so a December
     * retirement is inside it and 1 January of the next year is the first day out.
     */
    public function test_a_retirement_outside_the_period_is_refused(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => ($year + 1).'-01-01'],
            ],
        ]))->assertSessionHasErrors('officers.0.date_of_retirement');

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'date_of_retirement' => $year.'-12-31'],
            ],
        ]))->assertSessionHasNoErrors();
    }

    /**
     * Item 4, and the case a single date could never express: a deputation that
     * spans the whole window without starting or ending inside it.
     */
    public function test_a_deputation_overlapping_the_period_adds_a_vacancy(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'deputation_from' => ($year - 1).'-11-01', 'deputation_to' => ($year + 1).'-02-01'],
                ...$this->vacant(3, 2),
            ],
        ]))->assertSessionHasNoErrors();

        $breakdown = app(VacancyEngine::class)->calculate($this->proposal->refresh());

        $this->assertSame(1, $breakdown->deputationVacancy);

        // Four sanctioned posts and nobody substantively holding one — the officer
        // is away across the whole window — so all four are vacant.
        $this->assertSame(4, $breakdown->totalVacancy);
    }

    /**
     * A deputation with no return date means the officer is still away, which is the
     * clearest instance of item 4 and must count.
     */
    public function test_an_open_ended_deputation_counts(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'deputation_from' => $year.'-04-01'],
                ...$this->vacant(3, 2),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, app(VacancyEngine::class)->calculate($this->proposal->refresh())->deputationVacancy);
    }

    public function test_a_deputation_outside_the_period_is_refused(): void
    {
        $year = $this->proposal->dpc_year;

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id,
                    'deputation_from' => ($year + 1).'-01-01', 'deputation_to' => ($year + 1).'-06-01'],
            ],
        ]))->assertSessionHasErrors('officers.0.deputation_from');
    }

    /**
     * Items 6 and 7, and the audit item 10 requires.
     */
    public function test_without_category_wise_reservation_no_category_is_asked_and_rows_join_one_pool(): void
    {
        config(['dpc.reservation.category_wise' => false]);
        $st = ReservationCategory::query()->where('code', 'ST')->firstOrFail();

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk()
            ->assertDontSee('[reservation_category_id]', false);

        // Even a category posted by an old form is replaced by the pool.
        $payload = $this->payload();
        $payload['officers'][0]['reservation_category_id'] = $st->id;
        unset($payload['officers'][1]['reservation_category_id']);

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$this->ur->id],
            $this->proposal->workingOfficers()->pluck('reservation_category_id')->unique()->values()->all(),
        );
    }

    public function test_the_calculation_is_recorded_with_the_promotion_split(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.working-officers.update', $this->proposal),
            $this->payload(),
        )->assertSessionHasNoErrors();

        $calculation = app(VacancyCalculationRecorder::class)->current($this->proposal->refresh());

        $this->assertNotNull($calculation);
        $this->assertSame(4, $calculation->sanctioned_strength);
        $this->assertSame(1, $calculation->working_strength);
        $this->assertSame(3, $calculation->base_vacancy);
        $this->assertSame(3, $calculation->total_vacancy);

        /*
         | Items 6 and 7 as the business directive of 2026-09-02 reads them.
         |
         | The share divides the **chairs**, not the finished vacancy: four
         | sanctioned posts at 60% give two promotion chairs (floored) and two
         | direct. One promotion chair is held by the officer on the chart, so the
         | DPC gets one seat; the two direct chairs are unheld, so Direct
         | Recruitment gets two. The three add back up to the total, which is what
         | the old `Total × %` could not guarantee.
         |
         | `promotion_vacancy_exact` now records the chair count the rounding rule
         | acted on rather than a fraction of the total — there is no fraction left,
         | because a chair belongs to a quota or it does not. The rule is BD-33.
         */
        $this->assertSame('60.00', $calculation->promotion_percentage);
        $this->assertSame('2.00', $calculation->promotion_vacancy_exact);
        $this->assertSame(1, $calculation->promotion_vacancy);
        $this->assertSame(2, $calculation->direct_recruitment_vacancy);
        // Rule 1.3 (half up) since 2026-10-15; 2.4 rounds to 2 either way.
        $this->assertSame('round', $calculation->rounding_rule);
        $this->assertSame(3, $calculation->promotion_vacancy + $calculation->direct_recruitment_vacancy);
    }

    public function test_recalculating_supersedes_rather_than_overwrites(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());
        $first = app(VacancyCalculationRecorder::class)->current($this->proposal->refresh());

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());

        $this->assertNotNull($first->refresh()->superseded_by_id);
        $this->assertSame(2, DpcVacancyCalculation::query()->where('dpc_proposal_id', $this->proposal->id)->count());
        $this->assertSame(1, DpcVacancyCalculation::query()->where('dpc_proposal_id', $this->proposal->id)->current()->count());
    }

    public function test_a_recorded_calculation_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());

        $calculation = app(VacancyCalculationRecorder::class)->current($this->proposal->refresh());

        try {
            $calculation->forceFill(['total_vacancy' => 99])->save();
            $this->fail('A recorded vacancy calculation must refuse to be rewritten.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->expectException(RuntimeException::class);
        $calculation->delete();
    }

    public function test_vacancies_and_working_officers_are_immutable_once_submitted(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload())->assertForbidden();
    }

    public function test_service_layer_rejects_mutation_after_submission_even_bypassing_http(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $service = app(DpcProposalService::class);
        $submitted = $this->proposal->fresh();

        $this->expectException(WorkflowException::class);
        $service->saveWorkingOfficers($submitted, [['sn' => 1, 'name' => 'Tampered', 'dob' => '1970-01-01', 'reservation_category_id' => $this->ur->id]]);
    }
}
