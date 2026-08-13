<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

/**
 * Evaluation / Tabulation Chart (PPT S18) — M4 Phase 5.
 */
class DpcEvaluationChartTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $sn, array $overrides = []): array
    {
        return array_merge([
            'sn' => $sn,
            'employee_number' => "EMP-{$sn}",
            'name' => "US{$sn}",
            'dob' => '1964-03-20',
            'qualification' => '11th',
            'current_post' => 'Under Secretary',
            'feeder_post' => 'Under Secretary',
            'reservation_category_id' => $this->ur->id,
            'date_of_joining_service' => '1985-04-01',
            'date_of_posting_on_current_post' => '2015-06-01',
            'date_of_posting_on_feeder_post' => '2012-01-15',
            'tribal_area_service_years' => 2,
            'note' => 'PUNISHMENT',
            'cr_grades' => [
                ['year' => 2012, 'grade' => 'क+'],
                ['year' => 2013, 'grade' => 'क'],
                ['year' => 2014, 'grade' => 'ख'],
                ['year' => 2015, 'grade' => 'ग'],
                ['year' => 2016, 'grade' => 'घ'],
            ],
            'loyalty_flags' => [
                ['year' => 2012, 'flag' => '1'],
                ['year' => 2013, 'flag' => '0'],
                ['year' => 2014, 'flag' => null],
                ['year' => 2015, 'flag' => '1'],
                ['year' => 2016, 'flag' => '1'],
            ],
            'property_flags' => [
                ['year' => 2012, 'flag' => '1'],
                ['year' => 2013, 'flag' => '1'],
                ['year' => 2014, 'flag' => '1'],
                ['year' => 2015, 'flag' => null],
                ['year' => 2016, 'flag' => '0'],
            ],
        ], $overrides);
    }

    public function test_ao_can_save_the_evaluation_chart_with_per_year_values(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1), $this->row(2)]],
        )->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();
        $this->assertSame(2, $this->proposal->evaluationRows()->count());

        $first = $this->proposal->evaluationRows()->where('sn', 1)->firstOrFail();
        $this->assertSame('EMP-1', $first->employee_number);
        $this->assertSame(2, $first->tribal_area_service_years);
        $this->assertSame('PUNISHMENT', $first->note);
        $this->assertSame('2012-01-15', $first->date_of_posting_on_feeder_post?->format('Y-m-d'));

        $this->assertSame(5, $first->crGrades()->count());
        $this->assertSame('क+', $first->crGrades()->where('year', 2012)->firstOrFail()->grade);
        $this->assertSame('घ', $first->crGrades()->where('year', 2016)->firstOrFail()->grade);

        $this->assertTrue($first->loyaltyFlags()->where('year', 2012)->firstOrFail()->flag);
        $this->assertFalse($first->loyaltyFlags()->where('year', 2013)->firstOrFail()->flag);
        $this->assertNull($first->loyaltyFlags()->where('year', 2014)->firstOrFail()->flag);
        $this->assertNull($first->propertyFlags()->where('year', 2015)->firstOrFail()->flag);
    }

    public function test_chart_screen_renders_empty_and_repopulates_saved_years(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertOk();

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1)]],
        );

        // Exercises the Blade merge that folds the three per-year tables back
        // into one grid of year columns for display.
        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))
            ->assertOk()
            ->assertSee('US1')
            ->assertSee('2012');

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('Evaluation / Tabulation Chart')
            ->assertSee('US1');
    }

    public function test_officer_name_cannot_break_out_of_the_seeded_json_or_the_table(): void
    {
        // The chart seeds saved rows into a <script> block, so a stored name is
        // a script-injection vector if it is ever emitted unescaped.
        $payload = '</script><script>alert(1)</script>';

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, ['name' => $payload, 'note' => $payload])]],
        );

        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))
            ->assertOk()
            ->assertDontSee($payload, false);

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertDontSee($payload, false);
    }

    public function test_chart_stores_raw_data_only_and_computes_nothing(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1)]],
        );

        $columns = array_keys($this->proposal->evaluationRows()->firstOrFail()->getAttributes());

        // M5 owns every calculation — no score, eligibility, zone, or service
        // total may be persisted by M4 (architect directive).
        foreach (['mark', 'remark', 'total', 'score', 'service_years', 'experience', 'zone', 'eligible'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "Evaluation rows must not persist a computed `{$forbidden}` column.");
        }
    }

    public function test_incomplete_chart_is_accepted_for_a_circulation_case(): void
    {
        // A Circulation DPC exists precisely because CR years or property
        // returns are missing, so a partial chart must save.
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, [
                'cr_grades' => [['year' => 2015, 'grade' => null], ['year' => 2016, 'grade' => 'क']],
                'loyalty_flags' => [],
                'property_flags' => [['year' => 2016, 'flag' => null]],
            ])]],
        )->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $row = $this->proposal->evaluationRows()->firstOrFail();
        $this->assertSame(2, $row->crGrades()->count());
        $this->assertNull($row->crGrades()->where('year', 2015)->firstOrFail()->grade);
        $this->assertSame(0, $row->loyaltyFlags()->count());
    }

    public function test_reservation_category_must_reference_a_real_master(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, ['reservation_category_id' => 999999])]],
        )->assertSessionHasErrors('rows.0.reservation_category_id');
    }

    public function test_unknown_cr_grade_is_rejected(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, ['cr_grades' => [['year' => 2015, 'grade' => 'A+']]])]],
        )->assertSessionHasErrors('rows.0.cr_grades.0.grade');
    }

    public function test_cr_years_are_capped_by_configuration(): void
    {
        config(['dpc.evaluation.max_cr_years' => 5]);

        $sixYears = [];
        foreach (range(2011, 2016) as $year) {
            $sixYears[] = ['year' => $year, 'grade' => 'क'];
        }

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, ['cr_grades' => $sixYears])]],
        )->assertSessionHasErrors('rows.0.cr_grades');
    }

    public function test_the_ppt_s18_worked_example_row_saves_under_default_configuration(): void
    {
        // S18 row 1 (US1): six CR year columns 2012-2017, immovable-property
        // years 2013-2017, loyalty Y. Pinned because the default CR-year cap
        // must follow the chart (6 columns), not the five-year scoring window
        // — that window is M5's rule, not a data-entry limit.
        $crYears = [
            ['year' => 2012, 'grade' => 'क+'],
            ['year' => 2013, 'grade' => 'क'],
            ['year' => 2014, 'grade' => 'ख'],
            ['year' => 2015, 'grade' => 'क'],
            ['year' => 2016, 'grade' => 'क'],
            ['year' => 2017, 'grade' => 'क+'],
        ];
        $propertyYears = array_map(
            static fn (int $year): array => ['year' => $year, 'flag' => '1'],
            range(2013, 2017),
        );

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, [
                'name' => 'US1',
                'dob' => '1964-03-20',
                'qualification' => '11TH',
                'date_of_posting_on_feeder_post' => '2012-01-01',
                'note' => null,
                'cr_grades' => $crYears,
                'loyalty_flags' => [['year' => 2017, 'flag' => '1']],
                'property_flags' => $propertyYears,
            ])]],
        )->assertSessionHasNoErrors()->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $row = $this->proposal->evaluationRows()->firstOrFail();
        $this->assertSame(6, $row->crGrades()->count());
        $this->assertSame(5, $row->propertyFlags()->count());
        $this->assertSame('क+', $row->crGrades()->where('year', 2017)->firstOrFail()->grade);
    }

    public function test_duplicate_serial_number_is_rejected(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1), $this->row(1)]],
        )->assertSessionHasErrors('rows');
    }

    public function test_duplicate_year_within_one_officer_is_rejected(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1, ['cr_grades' => [
                ['year' => 2015, 'grade' => 'क'],
                ['year' => 2015, 'grade' => 'ख'],
            ]])]],
        )->assertSessionHasErrors('rows.0.cr_grades');
    }

    public function test_saving_replaces_previous_rows_and_their_year_children(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1), $this->row(2)]],
        );

        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(3, ['cr_grades' => [['year' => 2016, 'grade' => 'क']], 'loyalty_flags' => [], 'property_flags' => []])]],
        );

        $this->proposal->refresh();
        $this->assertSame(1, $this->proposal->evaluationRows()->count());
        $this->assertDatabaseCount('dpc_evaluation_cr_grades', 1);
        $this->assertDatabaseCount('dpc_evaluation_loyalty_flags', 0);
        $this->assertDatabaseCount('dpc_evaluation_property_flags', 0);
    }

    public function test_another_departments_officer_cannot_reach_the_chart(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->create(['department_id' => $other->id]);
        $intruder->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($intruder)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertForbidden();
        $this->actingAs($intruder)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(1)]],
        )->assertForbidden();
    }

    public function test_evaluation_chart_is_immutable_once_submitted(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.evaluation-chart.update', $this->proposal), ['rows' => [$this->row(1)]]);
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => [$this->row(2)]],
        )->assertForbidden();
    }

    public function test_service_layer_rejects_mutation_after_submission_even_bypassing_http(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.evaluation-chart.update', $this->proposal), ['rows' => [$this->row(1)]]);
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $service = app(DpcProposalService::class);
        $submitted = $this->proposal->fresh();

        $this->expectException(WorkflowException::class);
        $service->saveEvaluationRows($submitted, [$this->row(99)]);
    }
}
