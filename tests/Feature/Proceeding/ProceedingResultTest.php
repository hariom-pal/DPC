<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\TestCase;

/**
 * CALCULATE, result, approval and cancellation (PPT S33–S34) — M7 Phase 3.
 *
 * The centrepiece is `test_the_ppt_worked_example_is_reproduced_exactly`: the
 * customer's own answer key on S33 shows US3 scoring **14** and marked `NE`,
 * then — once `TRIBAL 2021` is recorded — scoring **15** and moving to the
 * waiting list. Reproducing that end to end is the strongest available
 * evidence that the correction pipeline and the frozen engine agree with the
 * Commission's own arithmetic.
 */
class ProceedingResultTest extends TestCase
{
    use RefreshDatabase;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private DpcProposal $proposal;

    private DpcProceeding $proceeding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->chairman = User::factory()->create(['department_id' => null, 'name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['department_id' => null, 'name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        // Class-I → Class-I, so §5.4's ≥15 threshold and §5.3's last-year gate
        // both apply — the PPT's own worked case.
        $postFrom = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600001',
            'forwarded_at' => now(),
        ]);

        $this->seedChart();
        $this->seedVacancy();

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($this->proposal->fresh(), $this->memberOne, request());
        $scheduling->schedule($this->proposal->fresh(), $this->memberOne, request(), Carbon::parse('2026-07-12'), '14:00');

        $this->proceeding = app(ProceedingService::class)
            ->open($this->proposal->fresh(), $this->memberOne, request());
    }

    // ----------------------------------------------------------------- helpers

    /**
     * US1 and US3 from PPT S33. US1's five most recent grades sum to 15 (क+,
     * क, ख, क, क → 4+3+2+3+3); US3's sum to 14 (ख, क, क, क, क → 2+3+3+3+3).
     */
    private function seedChart(): void
    {
        $ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();
        $st = ReservationCategory::query()->where('code', 'ST')->first()
            ?? ReservationCategory::query()->where('is_reserved', true)->firstOrFail();

        $this->officer(1, 'US1', $ur->id, [2012 => 'क', 2013 => 'क+', 2014 => 'क', 2015 => 'ख', 2016 => 'क', 2017 => 'क']);
        $this->officer(3, 'US3', $st->id, [2012 => 'क', 2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
    }

    /**
     * @param  array<int, string>  $grades
     */
    private function officer(int $sn, string $name, int $categoryId, array $grades): void
    {
        $row = $this->proposal->evaluationRows()->create([
            'sn' => $sn,
            'name' => $name,
            'dob' => '1967-04-09',
            'qualification' => 'BA',
            'reservation_category_id' => $categoryId,
            'tribal_area_service_years' => null,
        ]);

        foreach ($grades as $year => $grade) {
            $row->crGrades()->create(['year' => $year, 'grade' => $grade]);
            $row->loyaltyFlags()->create(['year' => $year, 'flag' => true]);
            $row->propertyFlags()->create(['year' => $year, 'flag' => true]);
        }
    }

    private function seedVacancy(): void
    {
        $this->proposal->vacancies()->create([
            'vacancy_type_id' => VacancyType::query()->firstOrFail()->id,
            'reservation_category_id' => ReservationCategory::query()->where('code', 'UR')->firstOrFail()->id,
            'count' => 1,
        ]);
    }

    private function calculations(): ProceedingCalculationService
    {
        return app(ProceedingCalculationService::class);
    }

    private function corrections(): ProceedingCorrectionService
    {
        return app(ProceedingCorrectionService::class);
    }

    private function calculate(?User $as = null)
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.proceedings.calculate', $this->proceeding));
    }

    private function approve(array $payload = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.proceedings.approve', $this->proceeding),
                array_merge(['promotion_as_on_year' => 2017], $payload));
    }

    private function resultFor(string $name, DpcCalculationRun $run): object
    {
        return $run->results()->where('officer_name', $name)->firstOrFail();
    }

    // ------------------------------------------------------------- CALCULATE

    public function test_calculate_produces_a_run_with_results(): void
    {
        $this->calculate()->assertSessionHasNoErrors();

        $run = DpcCalculationRun::query()->firstOrFail();
        $this->assertSame(2, $run->officers_considered);
        $this->assertSame(2, $run->results()->count());
        $this->assertSame($run->id, $this->proceeding->fresh()->latest_calculation_run_id);
    }

    public function test_calculate_never_writes_to_the_frozen_evaluation_rows(): void
    {
        // The corrected chart is materialised in memory only. A calculation
        // that wrote through those copies would silently unfreeze M4.
        $this->corrections()->record(
            $this->proceeding, $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $before = DpcEvaluationRow::query()->where('sn', 3)->firstOrFail();
        $gradeBefore = $before->crGrades->firstWhere('year', 2013)?->grade;

        $this->calculate();

        $after = DpcEvaluationRow::query()->where('sn', 3)->firstOrFail();
        $this->assertNull($after->tribal_area_service_years);
        $this->assertSame($gradeBefore, $after->crGrades->firstWhere('year', 2013)?->grade);
    }

    /**
     * PPT S33's own answer key, reproduced end to end.
     */
    public function test_the_ppt_worked_example_is_reproduced_exactly(): void
    {
        // Stage 2 of S33: US3 scores 14 and is not eligible for Class-I → I.
        $this->calculate();
        $run = $this->calculations()->latestRun($this->proceeding->fresh());

        $us3 = $this->resultFor('US3', $run);
        $this->assertSame(14, $us3->score_breakdown['total']);
        $this->assertFalse($us3->is_eligible);
        $this->assertSame('NE', $us3->remark);

        // Stage 3 of S33: TRIBAL is recorded against US3.
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2021',
            'Tribal-area service confirmed from the department record at the sitting.',
        );

        $this->calculate();
        $run = $this->calculations()->latestRun($this->proceeding->fresh());

        // 14 + the single §5.2 bonus of +1 = 15, which meets the ≥15 threshold.
        $us3 = $this->resultFor('US3', $run);
        $this->assertSame(15, $us3->score_breakdown['total']);
        $this->assertTrue($us3->is_eligible);

        // US1 takes the single UR vacancy; US3 goes to the waiting list.
        $this->assertSame(ListType::Main, $this->resultFor('US1', $run)->list_type);
        $this->assertSame(ListType::Waiting, $us3->list_type);
    }

    public function test_a_cr_grade_correction_changes_the_score(): void
    {
        $this->calculate();
        $before = $this->resultFor('US3', $this->calculations()->latestRun($this->proceeding->fresh()));
        $this->assertSame(14, $before->score_breakdown['total']);

        // 2013's ख (2) becomes क+ (4).
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::CrGrade, 3, 2013, 'क+',
            'CR for 2013 was transcribed incorrectly by the department.',
        );
        $this->calculate();

        $after = $this->resultFor('US3', $this->calculations()->latestRun($this->proceeding->fresh()));
        $this->assertSame(16, $after->score_breakdown['total']);
    }

    public function test_recalculating_creates_a_new_run_and_keeps_the_old_one(): void
    {
        $this->calculate();
        $first = DpcCalculationRun::query()->firstOrFail();

        $this->calculate();

        $this->assertSame(2, DpcCalculationRun::query()->count());
        $this->assertSame($first->id, DpcCalculationRun::query()->orderBy('id')->firstOrFail()->id);
        $this->assertNotSame($first->id, $this->proceeding->fresh()->latest_calculation_run_id);
    }

    public function test_calculation_history_lists_every_run(): void
    {
        $this->calculate();
        $this->calculate();

        $this->assertCount(2, $this->calculations()->history($this->proceeding->fresh()));
    }

    public function test_calculate_records_a_timeline_event_carrying_the_proceeding_uuid(): void
    {
        $this->calculate();

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.calculate')->firstOrFail();
        $p = $entry->properties;

        $this->assertSame($this->proceeding->uuid, $p['proceeding_uuid']);
        $this->assertNotEmpty($p['run_uuid']);
        $this->assertNotEmpty($p['rule_version']);
        $this->assertSame(0, $p['calculated_through_sequence']);
        $this->assertArrayHasKey('has_pending_decisions', $p);
    }

    public function test_calculate_is_not_a_workflow_transition(): void
    {
        // It produces an artefact but changes no state, so the ledger must not
        // claim one (Directive 4).
        $before = WorkflowTransition::query()->count();

        $this->calculate();

        $this->assertSame($before, WorkflowTransition::query()->count());
    }

    public function test_a_concluded_proceeding_cannot_be_calculated(): void
    {
        $this->calculate();
        $this->approve();

        $this->calculate()->assertForbidden();
    }

    // --------------------------------------------------- stale protection

    public function test_a_calculation_is_stale_before_anything_is_calculated(): void
    {
        $this->assertTrue($this->calculations()->isStale($this->proceeding));
    }

    public function test_a_correction_makes_the_current_calculation_stale(): void
    {
        $this->calculate();
        $this->assertFalse($this->calculations()->isStale($this->proceeding->fresh()));

        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $this->assertTrue($this->calculations()->isStale($this->proceeding->fresh()));
    }

    public function test_staleness_is_decided_by_sequence_not_by_timestamp(): void
    {
        // `applied_at` and `executed_at` have second granularity, so a
        // correction and a recalculation inside one second would be
        // indistinguishable by time — and the failure would be silent.
        Carbon::setTestNow('2026-07-12 14:00:00');

        $this->calculate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $this->assertTrue($this->calculations()->isStale($this->proceeding->fresh()));
        Carbon::setTestNow();
    }

    public function test_a_stale_result_cannot_be_approved(): void
    {
        $this->calculate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $this->approve()->assertSessionHasErrors('result');

        $this->assertSame(DpcProceedingStatus::InProceeding, $this->proceeding->fresh()->status);
    }

    public function test_the_refusal_names_the_officers_affected(): void
    {
        $this->calculate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $this->approve()->assertSessionHasErrorsIn('default', ['result']);

        $errors = session('errors')->get('result');
        $this->assertStringContainsString('US3', $errors[0]);
    }

    public function test_recalculating_clears_staleness_and_allows_approval(): void
    {
        $this->calculate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );
        $this->calculate();

        $this->approve()->assertSessionHasNoErrors();

        $this->assertSame(DpcProceedingStatus::ResultApproved, $this->proceeding->fresh()->status);
    }

    public function test_a_withdrawal_also_makes_the_calculation_stale(): void
    {
        // A withdrawal is itself a correction, so it must invalidate the
        // result exactly as the correction it reverses did.
        $override = $this->corrections()->record(
            $this->proceeding, $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );
        $this->calculate();
        $this->assertFalse($this->calculations()->isStale($this->proceeding->fresh()));

        $this->corrections()->withdraw(
            $this->proceeding->fresh(), $this->memberOne, $override->fresh(), 'Recorded against the wrong officer.',
        );

        $this->assertTrue($this->calculations()->isStale($this->proceeding->fresh()));
    }

    // ---------------------------------------------------------------- approval

    public function test_approval_pins_the_run_and_closes_the_sitting(): void
    {
        $this->calculate();
        $run = DpcCalculationRun::query()->firstOrFail();

        $this->approve(['promotion_as_on_year' => 2017])->assertSessionHasNoErrors();

        $proceeding = $this->proceeding->fresh();
        $this->assertSame(DpcProceedingStatus::ResultApproved, $proceeding->status);
        $this->assertSame($run->id, $proceeding->final_calculation_run_id);
        $this->assertSame(2017, $proceeding->promotion_as_on_year);
        $this->assertNotNull($proceeding->approved_at);
        // Directive 1 — closure identity.
        $this->assertSame($this->memberOne->id, $proceeding->closed_by);
        $this->assertSame('Member One', $proceeding->closed_by_name);
        $this->assertNotNull($proceeding->closed_at);
    }

    public function test_approval_requires_a_calculation(): void
    {
        $this->approve()->assertSessionHasErrors('result');

        $this->assertSame(DpcProceedingStatus::InProceeding, $this->proceeding->fresh()->status);
    }

    public function test_approval_requires_the_promotion_year(): void
    {
        // AQ-7 — captured, never derived.
        $this->calculate();

        $this->approve(['promotion_as_on_year' => ''])->assertSessionHasErrors('promotion_as_on_year');
    }

    public function test_an_approved_proceeding_is_frozen(): void
    {
        $this->calculate();
        $this->approve();

        $this->expectException(RuntimeException::class);
        $this->proceeding->fresh()->update(['promotion_as_on_year' => 2018]);
    }

    public function test_the_pinned_run_does_not_move_if_someone_calculates_again(): void
    {
        $this->calculate();
        $approvedRun = DpcCalculationRun::query()->firstOrFail();
        $this->approve();

        // A later calculation is refused outright, so the attested run cannot
        // be displaced even by a caller that bypasses the screen.
        $this->expectException(WorkflowException::class);

        try {
            $this->calculations()->calculate($this->proceeding->fresh(), $this->memberOne);
        } finally {
            $this->assertSame($approvedRun->id, $this->proceeding->fresh()->final_calculation_run_id);
        }
    }

    public function test_approval_records_a_workflow_transition_and_timeline_entry(): void
    {
        $this->calculate();
        $this->approve();

        $transition = WorkflowTransition::query()
            ->where('workflow', 'dpc_proceeding')->where('event', 'approve_result')->firstOrFail();
        $this->assertSame('in_proceeding', $transition->from_state);
        $this->assertSame('result_approved', $transition->to_state);

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.approve_result')->firstOrFail();
        $p = $entry->properties;
        $this->assertSame($this->proceeding->uuid, $p['proceeding_uuid']);
        $this->assertSame(2017, $p['promotion_as_on_year']);
        $this->assertSame('Member One', $p['closed_by_name']);
        $this->assertArrayHasKey('has_pending_decisions', $p);
    }

    public function test_approval_notifies_the_department(): void
    {
        Event::fake([WorkflowTransitioned::class]);

        $this->calculate();
        $this->approve();

        Event::assertDispatched(WorkflowTransitioned::class, function (WorkflowTransitioned $event): bool {
            return $event->event === 'approve_result'
                && in_array('authorized_officer', $event->notifyRoles, true)
                && in_array('administrative_head', $event->notifyRoles, true);
        });
    }

    public function test_approval_is_audited(): void
    {
        $this->calculate();
        $this->approve();

        $this->assertGreaterThan(
            0,
            Audit::query()->where('auditable_type', (new DpcProceeding)->getMorphClass())->count(),
        );
    }

    public function test_another_member_cannot_approve(): void
    {
        $this->calculate();

        $this->approve([], $this->memberTwo)->assertForbidden();
    }

    public function test_the_chairman_cannot_approve_a_sitting_he_is_not_conducting(): void
    {
        $this->calculate();

        $this->approve([], $this->chairman)->assertForbidden();
    }

    public function test_a_dpc_cannot_be_approved_twice(): void
    {
        $this->calculate();
        $this->approve();

        $this->approve()->assertForbidden();
    }

    // ------------------------------------------------------------ cancellation

    public function test_a_dpc_can_be_cancelled_with_a_reason(): void
    {
        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.cancel', $this->proceeding), [
                'reason' => 'The department has withdrawn the proposal for reconsideration.',
            ])
            ->assertSessionHasNoErrors();

        $proceeding = $this->proceeding->fresh();
        $this->assertSame(DpcProceedingStatus::Cancelled, $proceeding->status);
        $this->assertNotNull($proceeding->cancelled_at);
        $this->assertSame($this->memberOne->id, $proceeding->closed_by);
        $this->assertNotNull($proceeding->closed_at);
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.cancel', $this->proceeding), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(DpcProceedingStatus::InProceeding, $this->proceeding->fresh()->status);
    }

    public function test_a_cancelled_dpc_cannot_be_approved(): void
    {
        $this->calculate();
        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.cancel', $this->proceeding), [
                'reason' => 'The department has withdrawn the proposal for reconsideration.',
            ]);

        $this->approve()->assertForbidden();
    }

    public function test_a_cancellation_records_its_reason_on_the_timeline(): void
    {
        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.cancel', $this->proceeding), [
                'reason' => 'The department has withdrawn the proposal for reconsideration.',
            ]);

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.cancel_dpc')->firstOrFail();
        $this->assertStringContainsString('withdrawn the proposal', $entry->properties['cancellation_reason']);
        $this->assertSame('Member One', $entry->properties['closed_by_name']);
    }

    public function test_another_member_cannot_cancel(): void
    {
        $this->actingAs($this->memberTwo)
            ->post(route('cgpsc.proceedings.cancel', $this->proceeding), [
                'reason' => 'The department has withdrawn the proposal for reconsideration.',
            ])
            ->assertForbidden();
    }

    // --------------------------------------------------------------- screens

    public function test_the_result_screen_shows_both_lists(): void
    {
        $this->calculate();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.result', $this->proceeding))
            ->assertOk()
            ->assertSee('MAIN LIST')
            ->assertSee('WAITING LIST')
            // The officer must appear in a list body, not merely in a heading:
            // a broken list filter would still render both headings.
            ->assertSee('US1')
            ->assertSee('UR');
    }

    public function test_the_result_screen_warns_when_the_calculation_is_stale(): void
    {
        $this->calculate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '2',
            'Tribal-area service confirmed at the sitting.',
        );

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.result', $this->proceeding))
            ->assertOk()
            ->assertSee('Corrections have been recorded since this result was calculated');
    }

    public function test_an_approved_result_screen_shows_the_run_that_was_approved(): void
    {
        $this->calculate();
        $approved = DpcCalculationRun::query()->firstOrFail();
        $this->approve();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.result', $this->proceeding))
            ->assertOk()
            ->assertSee($approved->uuid);
    }

    public function test_the_chairman_may_read_the_result_without_approval_controls(): void
    {
        $this->calculate();

        $this->actingAs($this->chairman)
            ->get(route('cgpsc.proceedings.result', $this->proceeding))
            ->assertOk()
            ->assertDontSee('APPROVE BY CGPSC');
    }
}
