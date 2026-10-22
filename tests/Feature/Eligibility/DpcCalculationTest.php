<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Eligibility\Enums\CalculationStatus;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Eligibility\Services\ExplainCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SanctionsVacancies;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\Concerns\UsesPptPromotionRules;
use Tests\TestCase;

/**
 * CALCULATE — orchestration, persistence, Main/Waiting lists, immutability and
 * Explain Result (PPT S33, requirements §5.7) — M5 Phase 3.
 */
class DpcCalculationTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SanctionsVacancies;
    use SeedsEvaluationCharts;
    use UsesPptPromotionRules;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private ReservationCategory $ur;

    private ReservationCategory $st;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();
        $this->st = ReservationCategory::query()->where('code', 'ST')->firstOrFail();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $crGrades
     * @return array<string, mixed>
     */
    private function officer(int $sn, string $name, array $crGrades, ?ReservationCategory $category = null): array
    {
        $category ??= $this->ur;

        return [
            'sn' => $sn,
            'name' => $name,
            'employee_number' => "EMP-{$sn}",
            'dob' => '1964-03-20',
            'reservation_category_id' => $category->id,
            'cr_grades' => array_map(
                static fn (int $year, string $grade): array => ['year' => $year, 'grade' => $grade],
                array_keys($crGrades),
                array_values($crGrades),
            ),
            /*
             | Loyalty and Property, for every CR year on the row.
             |
             | These were empty until the change register of 2026-08-27, when items 13
             | to 15 made them mandatory: a Property Return missing from the five-year
             | window **rejects** the candidate, and a Loyalty Certificate missing
             | from a CR year holds them at CIR. An officer this class means to be
             | *promotable* therefore has to carry both, or every scoring assertion
             | below would be measuring a rejected row.
             |
             | Derived from the CR years rather than hardcoded, so a fixture that
             | changes its window keeps them in step — item 15 ties the certificate to
             | the CR year, and a mismatch here would produce a CIR nobody intended.
             */
            'loyalty_flags' => array_map(
                static fn (int $year): array => ['year' => $year, 'flag' => true],
                array_keys($crGrades),
            ),
            'property_flags' => array_map(
                static fn (int $year): array => ['year' => $year, 'flag' => true],
                array_keys($crGrades),
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $officers
     * @param  array<string, int>  $vacancies  category code => count
     */
    private function seedProposal(array $officers, array $vacancies = ['UR' => 2]): void
    {
        $service = app(DpcProposalService::class);
        $vacancyType = VacancyType::query()->orderBy('id')->firstOrFail();

        $rows = [];
        foreach ($vacancies as $code => $count) {
            $rows[] = [
                'vacancy_type_id' => $vacancyType->id,
                'reservation_category_id' => ReservationCategory::query()->where('code', $code)->firstOrFail()->id,
                'count' => $count,
            ];
        }

        $this->sanctionVacancies($this->proposal, 10, $rows);

        // Seniority List first, chart projected from it (business directive,
        // 2026-08-22). The officer arrays below are unchanged — the helper is
        // what splits their identity from their evaluation data — so every
        // scoring expectation in this file still describes the same officers.
        $this->seedSeniorityAndChart($this->proposal, $officers);
    }

    private function calculate(): DpcCalculationRun
    {
        return app(DpcCalculationService::class)->calculate($this->proposal->fresh(), $this->ao);
    }

    /**
     * Six officers, all Class-I to Class-I with 2 UR vacancies, so the zone is
     * 2 x 2 + 4 = 8 and every officer is inside it.
     */
    private function standardChart(): void
    {
        $this->seedProposal([
            $this->officer(1, 'US1', [2013 => 'क', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क+']),   // 15 EL
            $this->officer(2, 'US2', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),    // 15 EL
            $this->officer(3, 'US3', [2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),    // 14 NE
            $this->officer(4, 'US4', [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+']), // 20 SS
            $this->officer(5, 'US5', [2013 => 'क+', 2014 => 'घ', 2015 => 'क', 2016 => 'क+', 2017 => 'क+']),  // घ gate NE
            $this->officer(6, 'US6', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),    // 15 EL
        ]);
    }

    // ------------------------------------------------------------- the basics

    public function test_a_calculation_produces_a_completed_run_with_a_uuid(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $this->assertSame(CalculationStatus::Completed, $run->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $run->uuid);
        $this->assertSame(6, $run->officers_considered);
        $this->assertSame($this->ao->id, $run->executed_by);
    }

    public function test_the_uuid_is_the_public_route_identifier_not_the_auto_increment_id(): void
    {
        // Architect directive: a sequential id in a legal document discloses
        // how many calculations exist and invites guessing neighbours.
        $this->standardChart();
        $run = $this->calculate();

        $this->assertSame('uuid', $run->getRouteKeyName());
        $this->assertSame($run->uuid, $run->getRouteKey());
        $this->assertNotSame((string) $run->id, $run->getRouteKey());
    }

    public function test_every_officer_gets_a_result_row_including_the_unlisted(): void
    {
        // An absent name must be explained, not silently missing.
        $this->standardChart();
        $run = $this->calculate();

        $this->assertSame(6, $run->results()->count());
        $this->assertSame(2, $run->results()->where('list_type', ListType::None->value)->count());
    }

    // ------------------------------------------------- main and waiting lists

    public function test_vacancies_fill_the_main_list_and_the_rest_wait(): void
    {
        // 2 UR vacancies, 4 eligible UR officers -> 2 main, 2 waiting.
        $this->standardChart();
        $run = $this->calculate();

        $this->assertSame(4, $run->eligible_count);
        $this->assertSame(2, $run->main_list_count);
        $this->assertSame(2, $run->waiting_list_count);
    }

    public function test_a_super_seat_officer_takes_notional_seniority_over_seniors(): void
    {
        // US4 is fourth by seniority but has five क+ years, so §5.5's notional
        // seniority puts them first on the main list ahead of US1 and US2.
        $this->standardChart();
        $run = $this->calculate();

        $main = $run->mainList()->get();

        $this->assertSame(['US4', 'US1'], $main->pluck('officer_name')->all());
        $this->assertTrue($main->first()->is_super_seat);
    }

    public function test_remark_codes_follow_the_ppt_vocabulary(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $this->assertSame('UR', $run->results()->where('officer_name', 'US4')->firstOrFail()->remark);
        $this->assertSame('UR_W', $run->results()->where('officer_name', 'US2')->firstOrFail()->remark);
        $this->assertSame('NE', $run->results()->where('officer_name', 'US3')->firstOrFail()->remark);
    }

    public function test_without_category_wise_reservation_one_list_is_drawn_by_seniority(): void
    {
        // Today: no category-wise reservation in promotion (only PH). The same two
        // seats go to the two most senior eligible officers, whatever their category.
        config(['dpc.reservation.category_wise' => false]);

        $this->seedProposal([
            $this->officer(1, 'UR1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            $this->officer(2, 'UR2', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            $this->officer(3, 'ST1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'], $this->st),
        ], ['UR' => 1, 'ST' => 1]);

        $run = $this->calculate();

        $this->assertSame(['UR1', 'UR2'], $run->mainList()->get()->pluck('officer_name')->all());
        $this->assertSame('UR_W', $run->results()->where('officer_name', 'ST1')->firstOrFail()->remark);
    }

    public function test_lists_are_kept_per_reservation_category(): void
    {
        // One UR vacancy and one ST vacancy: each category fills its own seat,
        // so a more senior UR officer cannot take the ST seat.
        $this->seedProposal([
            $this->officer(1, 'UR1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            $this->officer(2, 'UR2', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            $this->officer(3, 'ST1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'], $this->st),
        ], ['UR' => 1, 'ST' => 1]);

        $run = $this->calculate();
        $main = $run->mainList()->get();

        $this->assertSame(['UR1', 'ST1'], $main->pluck('officer_name')->all());
        $this->assertSame('UR_W', $run->results()->where('officer_name', 'UR2')->firstOrFail()->remark);
    }

    public function test_officers_outside_the_zone_are_assessed_but_not_listed(): void
    {
        // One vacancy -> zone of 5. The sixth officer is eligible but outside.
        $this->seedProposal([
            $this->officer(1, 'US1', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
            $this->officer(2, 'US2', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
            $this->officer(3, 'US3', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
            $this->officer(4, 'US4', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
            $this->officer(5, 'US5', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
            $this->officer(6, 'US6', [2017 => 'क', 2016 => 'क', 2015 => 'क', 2014 => 'क', 2013 => 'क']),
        ], ['UR' => 1]);

        $run = $this->calculate();
        $sixth = $run->results()->where('officer_name', 'US6')->firstOrFail();

        $this->assertSame(5, $run->zone['size']);
        $this->assertFalse($sixth->in_zone);
        $this->assertTrue($sixth->is_eligible);
        $this->assertSame(ListType::None, $sixth->list_type);
        $this->assertSame('EL', $sixth->remark);
    }

    // ------------------------------------------------------- self-containment

    public function test_the_run_stores_everything_needed_to_stand_alone(): void
    {
        // Architect Decision 1 + directives: rule version, configuration
        // snapshot, years used, proposal version, executed by, timestamp, plus
        // build provenance.
        config([
            'dpc.application.version' => '1.4.2',
            'dpc.application.git_commit' => 'abc123def456',
            'dpc.application.build_timestamp' => '2026-08-01 09:30:00',
        ]);

        $this->standardChart();
        $run = $this->calculate();

        $this->assertNotSame('', $run->rule_version);
        $this->assertSame(15, $run->configuration_snapshot['qualifying_scores']['I-I']);
        $this->assertSame(64, strlen($run->proposal_version));
        $this->assertSame('1.4.2', $run->application_version);
        $this->assertSame('abc123def456', $run->git_commit_hash);
        $this->assertSame('2026-08-01 09:30:00', $run->build_timestamp->format('Y-m-d H:i:s'));
        $this->assertNotNull($run->executed_at);
        $this->assertNotEmpty($run->results()->firstOrFail()->score_breakdown['years_used']);
    }

    public function test_a_stored_run_rebuilds_its_own_rules(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $rules = $run->ruleSet();

        $this->assertSame($run->rule_version, $rules->version);
        $this->assertSame($run->configuration_snapshot, $rules->toArray());
    }

    public function test_a_later_configuration_change_cannot_rewrite_a_stored_calculation(): void
    {
        // The whole reason runs are self-contained.
        $this->standardChart();
        $run = $this->calculate();

        config(['dpc.scoring.qualifying_scores.I-I' => 99, 'dpc.scoring.rule_version' => '2027.9']);

        $reloaded = DpcCalculationRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertSame(15, $reloaded->ruleSet()->qualifyingScoreFor(
            $this->proposal->promotionFromPost->class_level,
            $this->proposal->promotionToPost->class_level,
        ));
        $this->assertNotSame('2027.9', $reloaded->rule_version);
    }

    public function test_the_proposal_fingerprint_changes_only_when_the_data_does(): void
    {
        $this->standardChart();
        $first = $this->calculate();
        $second = $this->calculate();

        $this->assertSame($first->proposal_version, $second->proposal_version);

        // The whole officer set has to be re-supplied now: the chart must always
        // carry exactly the Seniority List's officers, so a save that mentioned
        // only US1 would be a refusal rather than an edit. US1's grades change;
        // everybody else's are re-sent unchanged, which is what an officer
        // correcting one row on the downloaded template actually uploads.
        $rows = $this->proposal->fresh()->evaluationRows()->orderBy('sn')->get()
            ->map(fn ($row): array => [
                'sn' => $row->sn,
                'cr_grades' => $row->sn === 1
                    ? [
                        ['year' => 2013, 'grade' => 'घ'], ['year' => 2014, 'grade' => 'घ'],
                        ['year' => 2015, 'grade' => 'घ'], ['year' => 2016, 'grade' => 'घ'],
                        ['year' => 2017, 'grade' => 'घ'],
                    ]
                    : $row->crGrades->map->only(['year', 'grade'])->all(),
                'loyalty_flags' => $row->loyaltyFlags->map->only(['year', 'flag'])->all(),
                'property_flags' => $row->propertyFlags->map->only(['year', 'flag'])->all(),
            ])->all();

        app(DpcProposalService::class)->saveEvaluationRows($this->proposal->fresh(), $rows, $this->ao);

        $this->assertNotSame($first->proposal_version, $this->calculate()->proposal_version);
    }

    // ------------------------------------------------------------ immutability

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function immutableEvidence(): array
    {
        return [
            'a completed run cannot be edited by anyone' => ['run', 'update', 'immutable'],
            'a completed run cannot be deleted' => ['run', 'delete', null],
            // No role, including an administrator, may alter a result.
            'a calculation result cannot be edited' => ['result', 'update', 'immutable'],
            'a calculation result cannot be deleted' => ['result', 'delete', null],
        ];
    }

    #[DataProvider('immutableEvidence')]
    public function test_a_run_and_its_results_are_immutable(string $target, string $operation, ?string $message): void
    {
        $this->standardChart();
        $run = $this->calculate();
        $record = $target === 'run' ? $run : $run->results()->firstOrFail();

        $this->expectException(RuntimeException::class);

        if ($message !== null) {
            $this->expectExceptionMessage($message);
        }

        // A genuinely different value: Eloquent fires `updating` only when the model is
        // dirty, so re-setting the same value would prove nothing.
        $operation === 'update'
            ? $record->update($target === 'run' ? ['main_list_count' => 99] : ['remark' => 'TAMPERED'])
            : $record->delete();
    }

    public function test_recalculating_creates_a_new_run_and_leaves_the_old_one_intact(): void
    {
        $this->standardChart();
        $first = $this->calculate();
        $firstMain = $first->mainList()->pluck('officer_name')->all();

        $second = $this->calculate();

        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertSame(2, DpcCalculationRun::query()->count());

        $reloaded = DpcCalculationRun::query()->whereKey($first->id)->firstOrFail();
        $this->assertSame($firstMain, $reloaded->mainList()->pluck('officer_name')->all());
        $this->assertSame(CalculationStatus::Completed, $reloaded->status);
    }

    // ---------------------------------- Q5/Q6 — waiting for business decision

    public function test_a_reserved_shortfall_is_recorded_on_the_run_not_resolved(): void
    {
        $this->seedProposal([
            $this->officer(1, 'UR1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),
            // 13 marks: below the Class-I threshold, above the relaxed floor.
            $this->officer(2, 'ST1', [2013 => 'ख', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क'], $this->st),
        ], ['UR' => 1, 'ST' => 1]);

        $run = $this->calculate();

        $this->assertTrue($run->has_pending_decisions);
        $this->assertNotEmpty($run->pending_decisions);
        $this->assertStringContainsString('business decision', implode(' ', $run->pending_decisions));

        // Nothing was relaxed: the ST officer is still not eligible.
        $this->assertFalse($run->results()->where('officer_name', 'ST1')->firstOrFail()->is_eligible);
    }

    public function test_a_clean_calculation_records_no_pending_decisions(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $this->assertFalse($run->has_pending_decisions);
        $this->assertNull($run->pending_decisions);
    }

    // ------------------------------------------------------------ explain result

    public function test_a_result_explains_itself_from_stored_data_alone(): void
    {
        $this->standardChart();
        $run = $this->calculate();
        $explain = app(ExplainCalculationService::class);

        $superSeat = $run->results()->where('officer_name', 'US4')->firstOrFail();
        $lines = implode("\n", $explain->explainResult($superSeat));

        $this->assertStringContainsString('US4', $lines);
        $this->assertStringContainsString('Base total 20', $lines);
        $this->assertStringContainsString('Super Seat', $lines);
        $this->assertStringContainsString('Main List at position 1', $lines);
        $this->assertStringContainsString($run->rule_version, $lines);
    }

    public function test_an_explanation_states_every_ground_for_refusal(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $refused = $run->results()->where('officer_name', 'US5')->firstOrFail();
        $lines = implode("\n", app(ExplainCalculationService::class)->explainResult($refused));

        $this->assertStringContainsString('Not eligible', $lines);
        $this->assertStringContainsString('घ', $lines);
        $this->assertStringContainsString('Not placed on either list', $lines);
    }

    public function test_the_explanation_does_not_shift_when_configuration_changes(): void
    {
        // The directive's real purpose: an explanation that recomputed anything
        // would disagree with the result it claims to explain.
        $this->standardChart();
        $run = $this->calculate();
        $result = $run->results()->where('officer_name', 'US1')->firstOrFail();
        $explain = app(ExplainCalculationService::class);

        $before = $explain->explainResult($result);
        config(['dpc.scoring.qualifying_scores.I-I' => 99, 'dpc.scoring.cr_grade_marks' => ['क' => 99]]);
        $after = $explain->explainResult($result->fresh());

        $this->assertSame($before, $after);
    }

    public function test_a_run_explains_its_own_provenance(): void
    {
        config(['dpc.application.version' => '2.0.0', 'dpc.application.git_commit' => 'deadbeef']);

        $this->standardChart();
        $run = $this->calculate();

        $lines = implode("\n", app(ExplainCalculationService::class)->explainRun($run));

        $this->assertStringContainsString($run->uuid, $lines);
        $this->assertStringContainsString('2.0.0', $lines);
        $this->assertStringContainsString('deadbeef', $lines);
        $this->assertStringContainsString('2 × 2 + 4 = 8', $lines);
    }

    public function test_a_run_explanation_lists_the_pending_decisions(): void
    {
        $this->seedProposal([
            $this->officer(1, 'ST1', [2013 => 'ख', 2014 => 'ख', 2015 => 'क', 2016 => 'क', 2017 => 'क'], $this->st),
        ], ['ST' => 1]);

        $lines = implode("\n", app(ExplainCalculationService::class)->explainRun($this->calculate()));

        $this->assertStringContainsString('awaiting a business decision', $lines);
    }

    // -------------------------------------------------------------- audit etc.

    public function test_a_calculation_is_recorded_on_the_timeline_and_audited(): void
    {
        $this->standardChart();
        $run = $this->calculate();

        $this->assertTrue(
            ActivityTimeline::query()
                ->where('subject_type', $this->proposal->getMorphClass())
                ->where('subject_id', $this->proposal->id)
                ->where('event', 'dpc_proposal.calculation_completed')
                ->exists(),
        );

        $this->assertGreaterThan(0, $run->audits()->count());
    }

    public function test_calculating_an_empty_chart_is_refused(): void
    {
        // Vacancies only. Neither the Seniority List nor the chart is seeded,
        // which since 2026-08-22 is the only way to reach an empty chart — the
        // chart is projected, so it is empty exactly when the list is.
        $this->sanctionVacancies($this->proposal, 10);

        $this->expectException(WorkflowException::class);

        $this->calculate();
    }

    public function test_results_are_written_only_as_part_of_a_run(): void
    {
        // A stray result with no run would be unexplainable evidence.
        $this->standardChart();
        $this->calculate();

        $this->assertSame(0, DpcCalculationResult::query()->whereNull('dpc_calculation_run_id')->count());
    }
}
