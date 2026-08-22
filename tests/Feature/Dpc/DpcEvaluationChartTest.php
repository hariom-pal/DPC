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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

/**
 * Evaluation / Tabulation Chart (PPT S18) — M4 Phase 5, **reworked by the
 * business directive of 2026-08-22.**
 *
 * The chart's officers and their ten identity fields are now projected from the
 * approved Seniority List. This screen accepts evaluation data only: CR grades,
 * loyalty, immovable property, tribal-area service and a note.
 *
 * The tests are grouped by what they protect:
 *
 *   - **projection** — the chart appears from the Seniority List, and follows it;
 *   - **identity is not writable here** — the three ways somebody might try;
 *   - **the officer set is immutable** — no adding, removing or renumbering;
 *   - **evaluation data** — unchanged behaviour, including that a partial chart
 *     saves, because a Circulation DPC is defined by missing years.
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

        // One officer on the Seniority List by default, so the chart has exactly
        // one candidate and a single-row payload is the matching one.
        $this->withOfficers(1);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Put `$count` officers on the Seniority List, which projects the chart.
     */
    private function withOfficers(int $count): void
    {
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, array_map(
            fn (int $sn): array => [
                'seniority_number' => $sn,
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
            ],
            range(1, $count),
        ));

        $this->proposal->refresh();
    }

    /**
     * An evaluation payload for one officer — no identity, which is the point.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $sn, array $overrides = []): array
    {
        return array_merge([
            'sn' => $sn,
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

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function save(array $rows): TestResponse
    {
        return $this->actingAs($this->ao)->put(
            route('dpc-proposals.evaluation-chart.update', $this->proposal),
            ['rows' => $rows],
        );
    }

    // --------------------------------------------------------------- projection

    public function test_the_chart_appears_from_the_seniority_list_without_being_uploaded(): void
    {
        // The whole point of the directive: the department uploads the Seniority
        // List and the chart is already there, identity and all.
        $this->withOfficers(3);

        $rows = $this->proposal->fresh()->evaluationRows()->orderBy('sn')->get();

        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('sn')->map(fn ($sn): int => (int) $sn)->all());

        $first = $rows->firstWhere('sn', 1);
        $this->assertSame('EMP-1', $first->employee_number);
        $this->assertSame('US1', $first->name);
        $this->assertSame('1964-03-20', $first->dob->format('Y-m-d'));
        $this->assertSame('11th', $first->qualification);
        $this->assertSame('Under Secretary', $first->current_post);
        $this->assertSame('Under Secretary', $first->feeder_post);
        $this->assertSame($this->ur->id, $first->reservation_category_id);
        $this->assertSame('1985-04-01', $first->date_of_joining_service->format('Y-m-d'));
        $this->assertSame('2015-06-01', $first->date_of_posting_on_current_post->format('Y-m-d'));
        $this->assertSame('2012-01-15', $first->date_of_posting_on_feeder_post->format('Y-m-d'));
    }

    public function test_a_chart_row_takes_its_serial_from_the_seniority_number(): void
    {
        // So that "S.N. 4" means the same officer on both screens. It used to be
        // a free-standing serial the department typed again, and the two could
        // disagree without anything noticing.
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 7, 'name' => 'US7', 'dob' => '1964-03-20', 'reservation_category_id' => $this->ur->id],
        ]);

        $this->assertSame(7, (int) $this->proposal->fresh()->evaluationRows()->firstOrFail()->sn);
    }

    public function test_correcting_the_seniority_list_carries_evaluation_data_across(): void
    {
        $this->save([$this->row(1)])->assertSessionHasNoErrors();

        // A spelling fix on the Seniority List must not cost the department the
        // CR grades it has already entered.
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'EMP-1', 'name' => 'US1 (corrected)',
                'dob' => '1964-03-20', 'reservation_category_id' => $this->ur->id],
        ]);

        $row = $this->proposal->fresh()->evaluationRows()->firstOrFail();

        $this->assertSame('US1 (corrected)', $row->name);
        $this->assertSame(5, $row->crGrades()->count());
        $this->assertSame(2, $row->tribal_area_service_years);
    }

    public function test_an_officer_removed_from_the_seniority_list_leaves_the_chart(): void
    {
        $this->withOfficers(3);
        $this->save([$this->row(1), $this->row(2), $this->row(3)])->assertSessionHasNoErrors();

        $this->withOfficers(2);

        $this->assertSame(2, $this->proposal->fresh()->evaluationRows()->count());
        $this->assertSame(0, $this->proposal->fresh()->evaluationRows()->where('sn', 3)->count());
        // The departed officer's evaluation data goes with them.
        $this->assertDatabaseCount('dpc_evaluation_cr_grades', 10);
    }

    // -------------------------------------------- identity is not writable here

    public function test_identity_sent_with_the_payload_is_ignored(): void
    {
        // The Seniority List governs. A crafted request that supplies a different
        // name, date of birth or category is not an error — those fields simply
        // are not read — but it must not change the record either.
        $this->save([$this->row(1, [
            'name' => 'Somebody Else',
            'dob' => '1999-01-01',
            'qualification' => 'PhD',
            'current_post' => 'Chief Secretary',
            'reservation_category_id' => ReservationCategory::query()->where('code', 'ST')->firstOrFail()->id,
            'date_of_joining_service' => '2020-01-01',
        ])])->assertSessionHasNoErrors();

        $row = $this->proposal->fresh()->evaluationRows()->firstOrFail();

        $this->assertSame('US1', $row->name);
        $this->assertSame('1964-03-20', $row->dob->format('Y-m-d'));
        $this->assertSame('11th', $row->qualification);
        $this->assertSame('Under Secretary', $row->current_post);
        $this->assertSame($this->ur->id, $row->reservation_category_id);
        $this->assertSame('1985-04-01', $row->date_of_joining_service->format('Y-m-d'));
    }

    public function test_an_employee_number_that_disagrees_with_the_seniority_list_is_refused(): void
    {
        // Not ignored but *refused*, and the difference matters: an edited
        // spreadsheet that reassigns an employee number is how one officer's
        // confidential record ends up filed against another's name.
        $this->save([$this->row(1, ['employee_number' => 'EMP-999'])])
            ->assertSessionHasErrors('rows');

        $this->assertStringContainsString(
            'Employee numbers cannot be changed',
            session('errors')->first('rows'),
        );
    }

    public function test_the_screen_offers_no_identity_field_and_no_way_to_add_an_officer(): void
    {
        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.evaluation-chart', $this->proposal))
            ->assertOk();

        // Identity is rendered as text, so there is no input to re-enable.
        $response->assertDontSee('name="rows[0][name]"', escape: false);
        $response->assertDontSee('name="rows[0][dob]"', escape: false);
        $response->assertDontSee('name="rows[0][reservation_category_id]"', escape: false);
        $response->assertDontSee('id="add-row"', escape: false);

        // The officer's own fields are there.
        $response->assertSee('name="rows[0][tribal_area_service_years]"', escape: false);
        $response->assertSee('name="rows[0][note]"', escape: false);
        $response->assertSee('name="rows[0][sn]"', escape: false);
    }

    // ------------------------------------------------- the officer set is fixed

    public function test_an_extra_officer_is_refused(): void
    {
        $this->save([$this->row(1), $this->row(2)])->assertSessionHasErrors('rows');

        $this->assertStringContainsString(
            'The Evaluation Chart must contain exactly the same officers as the approved Seniority List.',
            session('errors')->first('rows'),
        );
    }

    public function test_a_missing_officer_is_refused(): void
    {
        $this->withOfficers(3);

        $this->save([$this->row(1), $this->row(2)])->assertSessionHasErrors('rows');
    }

    public function test_a_renumbered_officer_is_refused(): void
    {
        // The right *number* of rows, but not the right officers — which a count
        // check alone would let through.
        $this->withOfficers(2);

        $this->save([$this->row(1), $this->row(9)])->assertSessionHasErrors('rows');
    }

    public function test_the_service_layer_refuses_a_mismatched_set_even_bypassing_http(): void
    {
        $this->expectException(WorkflowException::class);

        app(DpcProposalService::class)->saveEvaluationRows(
            $this->proposal,
            [$this->row(1), $this->row(2)],
        );
    }

    public function test_the_chart_cannot_be_filled_in_before_the_seniority_list(): void
    {
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, []);

        $this->save([$this->row(1)])->assertSessionHasErrors('rows');

        $this->assertStringContainsString(
            'Seniority List must be uploaded',
            session('errors')->first('rows'),
        );
    }

    public function test_the_screen_reports_expected_against_present(): void
    {
        $this->withOfficers(4);

        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))
            ->assertOk()
            ->assertSee('Expected Officers')
            ->assertSee('Imported Officers');
    }

    // -------------------------------------------------------- evaluation data

    public function test_ao_can_save_the_evaluation_chart_with_per_year_values(): void
    {
        $this->withOfficers(2);

        $this->save([$this->row(1), $this->row(2)])
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();
        $this->assertSame(2, $this->proposal->evaluationRows()->count());

        $first = $this->proposal->evaluationRows()->where('sn', 1)->firstOrFail();

        // Identity from the Seniority List; evaluation data from the payload.
        $this->assertSame('EMP-1', $first->employee_number);
        $this->assertSame('2012-01-15', $first->date_of_posting_on_feeder_post->format('Y-m-d'));
        $this->assertSame(2, $first->tribal_area_service_years);
        $this->assertSame('PUNISHMENT', $first->note);

        $this->assertSame(5, $first->crGrades()->count());
        $this->assertSame('क+', $first->crGrades()->where('year', 2012)->firstOrFail()->grade);
        $this->assertSame('घ', $first->crGrades()->where('year', 2016)->firstOrFail()->grade);

        $this->assertTrue($first->loyaltyFlags()->where('year', 2012)->firstOrFail()->flag);
        $this->assertFalse($first->loyaltyFlags()->where('year', 2013)->firstOrFail()->flag);
        $this->assertNull($first->loyaltyFlags()->where('year', 2014)->firstOrFail()->flag);
        $this->assertNull($first->propertyFlags()->where('year', 2015)->firstOrFail()->flag);
    }

    public function test_chart_screen_renders_and_repopulates_saved_years(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertOk();

        $this->save([$this->row(1)]);

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

    public function test_officer_name_cannot_break_out_of_the_page(): void
    {
        // The name now arrives from the Seniority List, so that is where the
        // injection attempt has to be planted — and it must still be escaped
        // wherever the chart renders it.
        $payload = '</script><script>alert(1)</script>';

        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'name' => $payload, 'dob' => '1964-03-20',
                'reservation_category_id' => $this->ur->id],
        ]);

        $this->save([$this->row(1, ['note' => $payload])]);

        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))
            ->assertOk()
            ->assertDontSee($payload, false);

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertDontSee($payload, false);
    }

    public function test_chart_stores_raw_data_only_and_computes_nothing(): void
    {
        $this->save([$this->row(1)]);

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
        // returns are missing, so a partial chart must save. "Every officer must
        // be present" and "every year must be filled in" are different rules,
        // and only the first is enforced.
        $this->save([$this->row(1, [
            'cr_grades' => [['year' => 2015, 'grade' => null], ['year' => 2016, 'grade' => 'क']],
            'loyalty_flags' => [],
            'property_flags' => [['year' => 2016, 'flag' => null]],
        ])])->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $row = $this->proposal->evaluationRows()->firstOrFail();
        $this->assertSame(2, $row->crGrades()->count());
        $this->assertNull($row->crGrades()->where('year', 2015)->firstOrFail()->grade);
        $this->assertSame(0, $row->loyaltyFlags()->count());
    }

    public function test_unknown_cr_grade_is_rejected(): void
    {
        $this->save([$this->row(1, ['cr_grades' => [['year' => 2015, 'grade' => 'A+']]])])
            ->assertSessionHasErrors('rows.0.cr_grades.0.grade');
    }

    public function test_cr_years_are_capped_by_configuration(): void
    {
        config(['dpc.evaluation.max_cr_years' => 5]);

        $sixYears = [];
        foreach (range(2011, 2016) as $year) {
            $sixYears[] = ['year' => $year, 'grade' => 'क'];
        }

        $this->save([$this->row(1, ['cr_grades' => $sixYears])])
            ->assertSessionHasErrors('rows.0.cr_grades');
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

        $this->save([$this->row(1, [
            'note' => null,
            'cr_grades' => $crYears,
            'loyalty_flags' => [['year' => 2017, 'flag' => '1']],
            'property_flags' => $propertyYears,
        ])])->assertSessionHasNoErrors()->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $row = $this->proposal->evaluationRows()->firstOrFail();
        $this->assertSame(6, $row->crGrades()->count());
        $this->assertSame(5, $row->propertyFlags()->count());
        $this->assertSame('क+', $row->crGrades()->where('year', 2017)->firstOrFail()->grade);
    }

    public function test_duplicate_serial_number_is_rejected(): void
    {
        $this->save([$this->row(1), $this->row(1)])->assertSessionHasErrors('rows');
    }

    public function test_duplicate_year_within_one_officer_is_rejected(): void
    {
        $this->save([$this->row(1, ['cr_grades' => [
            ['year' => 2015, 'grade' => 'क'],
            ['year' => 2015, 'grade' => 'ख'],
        ]])])->assertSessionHasErrors('rows.0.cr_grades');
    }

    public function test_saving_replaces_the_previous_year_children(): void
    {
        $this->save([$this->row(1)]);
        $this->assertDatabaseCount('dpc_evaluation_cr_grades', 5);

        // The officer stays; only their evaluation data is replaced. Before the
        // directive this test asserted that the *officer rows* were replaced —
        // which is no longer a thing this screen can do.
        $this->save([$this->row(1, [
            'cr_grades' => [['year' => 2016, 'grade' => 'क']],
            'loyalty_flags' => [],
            'property_flags' => [],
        ])])->assertSessionHasNoErrors();

        $this->proposal->refresh();
        $this->assertSame(1, $this->proposal->evaluationRows()->count());
        $this->assertDatabaseCount('dpc_evaluation_cr_grades', 1);
        $this->assertDatabaseCount('dpc_evaluation_loyalty_flags', 0);
        $this->assertDatabaseCount('dpc_evaluation_property_flags', 0);
    }

    // ------------------------------------------------------------- the template

    public function test_the_prefilled_template_carries_every_officer_and_no_evaluation_data(): void
    {
        $this->withOfficers(2);

        $csv = (string) $this->actingAs($this->ao)
            ->get(route('dpc-proposals.evaluation-chart.template', $this->proposal))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines, 'a header and one row per officer');
        $this->assertStringContainsString('SN', $lines[0]);
        $this->assertStringContainsString('CR 2026', $lines[0]);
        $this->assertStringContainsString('EMP-1', $lines[1]);
        $this->assertStringContainsString('US1', $lines[1]);
        $this->assertStringContainsString('EMP-2', $lines[2]);

        // Identity filled, evaluation columns empty — the officer's whole job.
        $this->assertStringEndsWith(',,', trim($lines[1]));
    }

    public function test_the_prefilled_template_returns_evaluation_data_already_entered(): void
    {
        // Download → edit → upload must not be destructive: a second download
        // has to give back what the system holds, not a blank sheet.
        $this->save([$this->row(1)])->assertSessionHasNoErrors();

        $csv = (string) $this->actingAs($this->ao)
            ->get(route('dpc-proposals.evaluation-chart.template', $this->proposal))
            ->getContent();

        $this->assertStringContainsString('PUNISHMENT', $csv);
        $this->assertStringContainsString('क+', $csv);
    }

    public function test_the_template_covers_the_years_the_chart_actually_holds(): void
    {
        // **A data-loss fix.** The per-year columns span `max_cr_years` ending at
        // an anchor year. Anchored on the proposal date (2026) they were
        // 2021–2026, while this chart records 2012–2016 — so the department would
        // have downloaded a file with its CR grades *missing*, filled in the
        // blanks and uploaded a chart that silently wiped five years of records.
        $this->save([$this->row(1)])->assertSessionHasNoErrors();

        $csv = (string) $this->actingAs($this->ao)
            ->get(route('dpc-proposals.evaluation-chart.template', $this->proposal))
            ->getContent();

        foreach (range(2012, 2016) as $year) {
            $this->assertStringContainsString("CR {$year}", $csv, "the template must carry a column for CR {$year}");
        }

        // And the values, not just the headers.
        $this->assertStringContainsString('क+', $csv);
        $this->assertStringContainsString('घ', $csv);
    }

    public function test_the_template_falls_back_to_the_proposal_year_for_an_empty_chart(): void
    {
        // Nothing to anchor to, so the proposal's own year is the best guess at
        // the window a department will want to fill in.
        $csv = (string) $this->actingAs($this->ao)
            ->get(route('dpc-proposals.evaluation-chart.template', $this->proposal))
            ->getContent();

        $this->assertStringContainsString('CR 2026', $csv);
    }

    public function test_another_departments_officer_cannot_download_the_template(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->create(['department_id' => $other->id]);
        $intruder->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($intruder)
            ->get(route('dpc-proposals.evaluation-chart.template', $this->proposal))
            ->assertForbidden();
    }

    // ---------------------------------------------------- access and immutability

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
        $this->save([$this->row(1)]);
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertForbidden();
        $this->save([$this->row(1)])->assertForbidden();
    }

    public function test_service_layer_rejects_mutation_after_submission_even_bypassing_http(): void
    {
        $this->save([$this->row(1)]);
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $service = app(DpcProposalService::class);
        $submitted = $this->proposal->fresh();

        $this->expectException(WorkflowException::class);
        $service->saveEvaluationRows($submitted, [$this->row(1)]);
    }
}
