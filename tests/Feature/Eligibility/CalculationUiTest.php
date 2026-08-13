<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CALCULATE wiring, calculation history, Main/Waiting list UI and Explain
 * Result UI (PPT S17/S18/S33) — M5 Phase 4.
 */
class CalculationUiTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    private ReservationCategory $ur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    private function seedChart(): void
    {
        $service = app(DpcProposalService::class);

        $service->saveVacancies($this->proposal, 10, [[
            'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
            'reservation_category_id' => $this->ur->id,
            'count' => 1,
        ]]);

        $officer = fn (int $sn, string $name, array $grades): array => [
            'sn' => $sn,
            'name' => $name,
            'dob' => '1964-03-20',
            'reservation_category_id' => $this->ur->id,
            'cr_grades' => array_map(
                static fn (int $y, string $g): array => ['year' => $y, 'grade' => $g],
                array_keys($grades),
                array_values($grades),
            ),
            'loyalty_flags' => [],
            'property_flags' => [],
        ];

        $service->saveEvaluationRows($this->proposal, [
            $officer(1, 'US1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),      // 15 EL
            $officer(2, 'US2', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),      // 15 EL
            $officer(3, 'US3', [2013 => 'ख', 2014 => 'ख', 2015 => 'ख', 2016 => 'ख', 2017 => 'ख']),      // 10 NE
        ], $this->ao);

        $this->proposal->refresh();
    }

    private function calculate(): DpcCalculationRun
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal));

        return DpcCalculationRun::query()->latest('id')->firstOrFail();
    }

    // --------------------------------------------------------- calculate button

    public function test_the_calculate_button_appears_once_a_chart_exists(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('Eligibility Calculation')
            ->assertSee('nothing to calculate yet')
            ->assertDontSee('CALCULATE</button>', false);

        $this->seedChart();

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('CALCULATE');
    }

    public function test_calculating_redirects_to_the_result_addressed_by_uuid(): void
    {
        $this->seedChart();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasNoErrors();

        $run = DpcCalculationRun::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk();
    }

    public function test_calculating_an_empty_chart_reports_a_business_error(): void
    {
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal))
            ->assertSessionHasErrors('calculate');

        $this->assertSame(0, DpcCalculationRun::query()->count());
    }

    public function test_calculation_stays_available_after_the_proposal_is_submitted(): void
    {
        // The Commission calculates after submission, when the proposal itself
        // is no longer editable. Gating this on `update` would make the engine
        // unusable at exactly the point it is needed.
        $this->seedChart();
        app(DpcProposalService::class)->saveCommittee($this->proposal, [
            ['name' => 'Chairman', 'capacity' => 'chairman'],
        ], $this->ao);
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [[
            'seniority_number' => 1, 'name' => 'US1', 'dob' => '1964-03-20',
            'reservation_category_id' => $this->ur->id,
        ]], $this->ao);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->assertSame('submitted', $this->proposal->fresh()->status->value);

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DpcCalculationRun::query()->count());
    }

    // ------------------------------------------------------------------ history

    public function test_history_lists_every_run_and_never_replaces_one(): void
    {
        $this->seedChart();
        $first = $this->calculate();
        $second = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee($first->uuid)
            ->assertSee($second->uuid)
            ->assertSee('Nothing is ever replaced');
    }

    public function test_history_is_empty_before_any_calculation(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee('No calculation has been run');
    }

    // ------------------------------------------------------- lists and explain

    public function test_the_result_screen_shows_both_lists(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('मुख्य सूचि', false)
            ->assertSee('अनुपूरक सूचि', false)
            ->assertSee('US1')
            ->assertSee('US2')
            // Every officer appears, including the one on neither list.
            ->assertSee('US3')
            ->assertSee('All Officers Assessed');
    }

    public function test_the_result_screen_explains_the_run_itself(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('How this calculation was produced')
            ->assertSee($run->rule_version);
    }

    public function test_explain_result_is_reachable_per_officer(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 3]))
            ->assertOk()
            ->assertSee('Explain Result')
            ->assertSee('US3')
            ->assertSee('Grounds for refusal')
            ->assertSee('Qualifying score not met');
    }

    public function test_explain_result_shows_the_year_by_year_marks(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 1]))
            ->assertOk()
            ->assertSee('Year-by-year marks')
            ->assertSee('2017');
    }

    public function test_an_unknown_officer_in_a_run_is_not_found(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 99]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- identifiers

    public function test_runs_are_addressed_by_uuid_and_never_by_sequential_id(): void
    {
        // Architect Decision 5: UUID is the only public identifier.
        $this->seedChart();
        $run = $this->calculate();

        $url = route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]);

        $this->assertStringContainsString($run->uuid, $url);
        $this->assertStringNotContainsString('/calculations/'.$run->id, $url);

        // The sequential id must not resolve.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, (string) $run->id]))
            ->assertNotFound();
    }

    public function test_the_history_screen_does_not_leak_sequential_ids(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee($run->uuid)
            ->assertDontSee('/calculations/'.$run->id);
    }

    // ----------------------------------------------------------- authorization

    public function test_another_departments_officer_cannot_reach_any_calculation_screen(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->create(['department_id' => $other->id]);
        $intruder->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.index', $this->proposal))->assertForbidden();
        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))->assertForbidden();
        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 1]))->assertForbidden();
        $this->actingAs($intruder)->post(route('dpc-proposals.calculate', $this->proposal))->assertForbidden();
    }

    public function test_a_run_from_another_proposal_is_not_reachable_through_this_one(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->proposal->post_to_cadre_id,
            'post_from_cadre_id' => $this->proposal->post_from_cadre_id,
            'proposal_date' => '2026-06-26',
        ]);
        $otherProposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$otherProposal, $run->uuid]))
            ->assertNotFound();
    }

    public function test_the_administrative_head_can_view_and_run_a_calculation(): void
    {
        $this->seedChart();

        $this->actingAs($this->ah)->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasNoErrors();

        $run = DpcCalculationRun::query()->latest('id')->firstOrFail();
        $this->assertSame($this->ah->id, $run->executed_by);

        $this->actingAs($this->ah)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk();
    }

    // ---------------------------------------------- provisional results warning

    public function test_a_provisional_calculation_warns_on_screen(): void
    {
        // A run affected by the unruled reserved relaxation must say so
        // prominently — publishing it as final would publish an outcome under
        // rules nobody has approved.
        $st = ReservationCategory::query()->where('code', 'ST')->firstOrFail();
        $service = app(DpcProposalService::class);

        $service->saveVacancies($this->proposal, 10, [[
            'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
            'reservation_category_id' => $st->id,
            'count' => 1,
        ]]);
        $service->saveEvaluationRows($this->proposal, [[
            'sn' => 1, 'name' => 'ST1', 'dob' => '1964-03-20',
            'reservation_category_id' => $st->id,
            'cr_grades' => [
                ['year' => 2013, 'grade' => 'ख'], ['year' => 2014, 'grade' => 'ख'],
                ['year' => 2015, 'grade' => 'क'], ['year' => 2016, 'grade' => 'क'],
                ['year' => 2017, 'grade' => 'क'],
            ],
            'loyalty_flags' => [], 'property_flags' => [],
        ]], $this->ao);

        $run = $this->calculate();

        $this->assertTrue($run->has_pending_decisions);

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('This calculation is provisional')
            ->assertSee('awaiting a business decision');

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee('awaiting business decision');
    }

    // ------------------------------------- certificate readiness (directive 1)

    public function test_a_run_already_holds_everything_a_calculation_certificate_would_need(): void
    {
        // Architect directive: the persisted data must already be sufficient to
        // generate a certificate later. No PDF is required now — this asserts
        // the data is there so that phase is rendering, not re-deriving.
        $this->seedChart();
        $run = $this->calculate();

        foreach ([
            'uuid', 'rule_version', 'configuration_snapshot', 'proposal_version',
            'application_version', 'git_commit_hash', 'executed_at',
            'zone', 'officers_considered', 'eligible_count', 'main_list_count', 'waiting_list_count',
        ] as $field) {
            $this->assertNotNull($run->{$field}, "A certificate would need {$field}.");
        }

        $result = $run->results()->firstOrFail();
        foreach (['officer_name', 'reservation_category_code', 'score_breakdown', 'verdict', 'super_seat', 'remark'] as $field) {
            $this->assertNotNull($result->{$field}, "A certificate would need result.{$field}.");
        }

        $this->assertNotNull($run->proposal->dpcType);
    }

    // ------------------------------------------------------------- throttling

    public function test_calculate_is_throttled_so_evidence_tables_cannot_be_flooded(): void
    {
        // A calculation writes rows no code path can ever delete. Without a
        // limit, holding the button down grows the evidence tables without
        // bound and buries the run the Commission acted on.
        $this->seedChart();

        $max = (int) config('dpc.rate_limiting.calculate.max_attempts');

        for ($attempt = 0; $attempt < $max; $attempt++) {
            $this->actingAs($this->ao)
                ->post(route('dpc-proposals.calculate', $this->proposal))
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->ao)
            ->from(route('dpc-proposals.show', $this->proposal))
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasErrors('calculate');

        $this->assertSame($max, DpcCalculationRun::query()->count());
    }

    public function test_the_calculate_limit_is_per_proposal_not_per_user(): void
    {
        // Two proposals are two pieces of work; exhausting one must not stop
        // the other.
        $this->seedChart();

        $max = (int) config('dpc.rate_limiting.calculate.max_attempts');
        for ($attempt = 0; $attempt < $max; $attempt++) {
            $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal));
        }

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->proposal->post_to_cadre_id,
            'post_from_cadre_id' => $this->proposal->post_from_cadre_id,
            'proposal_date' => '2026-06-27',
        ]);
        $other = DpcProposal::query()->latest('id')->firstOrFail();

        // The second proposal has no chart, so the business error proves the
        // request reached the controller rather than the throttle.
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $other))
            ->assertRedirect(route('dpc-proposals.show', $other))
            ->assertSessionHasErrors('calculate');

        $this->assertSame(
            'This proposal has no evaluation chart entries, so there is nothing to calculate.',
            session('errors')->first('calculate'),
        );
    }
}
