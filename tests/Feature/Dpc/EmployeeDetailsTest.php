<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Master\Models\CrGradeValue;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\PromotionCrRuleYear;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Master\Services\PromotionCrRules;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Employee-wise details inside the Authorized Officer's DPC proposal — CR, Property
 * Return, the PH questionnaire, undertakings and documents (Coder Instructions,
 * points 2 and 3, rule 4.1).
 */
class EmployeeDetailsTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk'));

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $from = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);

        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Under Secretary'],
            ['seniority_number' => 2, 'employee_number' => 'E-2', 'name' => 'Officer Two', 'dob' => '1971-03-20', 'date_of_joining_service' => '1996-07-01', 'promotion_from_post' => 'Under Secretary'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());

        $this->row = $this->proposal->evaluationRows()->where('sn', 1)->sole();
    }

    private function route(string $name, array $extra = []): string
    {
        return route("dpc-proposals.employees.{$name}", [$this->proposal, $this->row, ...$extra]);
    }

    private function pdf(string $name = 'document.pdf', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function upload(EvaluationDocumentKind $kind, ?int $year = null, bool $undertaking = true, array $extra = []): TestResponse
    {
        return $this->actingAs($this->ao)->post($this->route('documents.store'), array_merge(['verified' => '1',
            'kind' => $kind->value,
            'year' => $year,
            'undertaking' => $undertaking ? '1' : '0',
            'document' => $this->pdf(),
        ], $extra));
    }

    private function details(): array
    {
        return app(EmployeeDetailsService::class)->status($this->row->refresh());
    }

    /**
     * @param  list<array<string, string|null>>  $reports
     */
    private function saveCr(int $year, array $reports): TestResponse
    {
        return $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'reports' => $reports,
        ]);
    }

    private static function report(string $grade): array
    {
        return [
            'reporter_designation' => 'Under Secretary', 'reporter_grade' => $grade,
            'reviewer_designation' => 'Deputy Secretary', 'reviewer_grade' => $grade,
            'accepting_designation' => 'Secretary', 'accepting_grade' => $grade,
        ];
    }

    // ===================================================== the list and the screen

    public function test_the_ao_sees_every_officer_with_a_completion_indicator(): void
    {
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.employees.index', $this->proposal))
            ->assertOk()
            ->assertSee('Officer One')
            ->assertSee('Officer Two')
            ->assertSee('Complete Details')
            ->assertSee('CR Pending')
            ->assertSee('Property Return Pending')
            ->assertSee('PH Pending')
            ->assertSee('0 / 2');
    }

    public function test_the_details_screen_renders_every_section(): void
    {
        $this->actingAs($this->ao)
            ->get($this->route('show'))
            ->assertOk()
            ->assertSee('A. Basic Information')
            ->assertSee('B. Seniority Information')
            ->assertSee('C. Confidential Reports (CR)')
            ->assertSee('D. Property Return (IPR)')
            ->assertSee('E. PH Reservation')
            ->assertSee('F. Undertakings and Supporting Documents')
            ->assertSee('G. Completion Status');
    }

    public function test_a_saved_section_is_there_when_the_ao_comes_back(): void
    {
        $category = ReservationCategory::query()->where('code', 'UR')->sole();

        $this->actingAs($this->ao)->put($this->route('basic'), [
            'reservation_category_id' => $category->id,
            'date_of_posting_on_promotion_from_post' => '2015-06-01',
            // Rule 4.4: the service-record orders and the undertaking.
            'dob_order_number' => 'SR/1', 'dob_order_date' => '1995-07-01',
            'joining_order_number' => 'APPT/1', 'joining_order_date' => '1995-07-01',
            'service_record_undertaking' => '1',
        ])->assertSessionHasNoErrors();

        $this->saveCr(2023, [self::report('क')])->assertSessionHasNoErrors();

        // Resume: the screen shows what was saved, and only that section moved on.
        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('2015-06-01', false)
            ->assertSee('data-cr-year="2023"', false);

        $status = $this->details();
        $this->assertSame([], $status['sections']['basic']);
        $this->assertNotSame([], $status['sections']['property']);
        $this->assertFalse($status['complete']);
    }

    // ===================================================================== CR

    public function test_a_single_report_takes_the_accepting_officers_remark(): void
    {
        $this->saveCr(2023, [[
            'reporter_grade' => 'ख', 'reviewer_grade' => 'क', 'accepting_grade' => 'क+',
            'reporter_designation' => 'A', 'reviewer_designation' => 'B', 'accepting_designation' => 'C',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('क+', $this->row->crGrades()->where('year', 2023)->value('grade'));
    }

    public function test_a_missing_remark_falls_back_and_needs_an_nrc(): void
    {
        $this->saveCr(2023, [['reporter_grade' => 'ख', 'reviewer_grade' => 'क', 'accepting_grade' => null]])
            ->assertSessionHasNoErrors();

        $this->assertSame('क', $this->row->crGrades()->where('year', 2023)->value('grade'), 'Reviewer, when the accepting remark is missing.');
        $this->assertContains('CR 2023: a reporting, reviewing or accepting remark is missing — upload the NRC.', $this->details()['sections']['cr']);

        $this->upload(EvaluationDocumentKind::CrNrc, 2023, false)->assertSessionHasNoErrors();

        $this->assertNotContains('CR 2023: a reporting, reviewing or accepting remark is missing — upload the NRC.', $this->details()['sections']['cr']);
    }

    public function test_several_reports_are_averaged_and_the_decimal_dropped(): void
    {
        // क+ = 4, ख = 2, ख = 2 → 8 / 3 = 2.67 → 2 = ख (never rounded up to क).
        $this->saveCr(2023, [self::report('क+'), self::report('ख'), self::report('ख')])->assertSessionHasNoErrors();

        $this->assertSame('ख', $this->row->crGrades()->where('year', 2023)->value('grade'));
    }

    public function test_at_most_four_reports_a_year(): void
    {
        $this->saveCr(2023, array_fill(0, 5, self::report('क')))->assertSessionHasErrors('reports');
    }

    public function test_a_cr_not_put_up_needs_its_reason_and_document(): void
    {
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '0'])->assertSessionHasNoErrors();

        $this->assertNull($this->row->crGrades()->where('year', 2022)->value('grade'), 'No grade is invented for a missing CR.');
        $this->assertStringContainsString('CR 2022: not put up', implode(' ', $this->details()['sections']['cr']));

        DpcEvaluationRecordGap::query()->create([
            'dpc_evaluation_row_id' => $this->row->id, 'kind' => 'cr', 'year' => 2022,
            'record_reason_id' => RecordReason::query()->where('code', 'nrc_issued')->value('id'),
            'disk' => 'local', 'path' => 'nrc.pdf', 'original_name' => 'nrc.pdf', 'size_bytes' => 1, 'sha256' => str_repeat('b', 64),
        ]);

        $this->assertStringNotContainsString('CR 2022', implode(' ', $this->details()['sections']['cr']));
    }

    public function test_the_rule_3_2_reasons_are_offered(): void
    {
        foreach (['nrc_issued', 'long_leave', 'suspended'] as $code) {
            $this->assertTrue(RecordReason::query()->where('code', $code)->exists(), $code);
        }
    }

    public function test_a_cr_without_grading_needs_the_cr_uploaded(): void
    {
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '1', 'has_grading' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->row->crGrades()->where('year', 2022)->value('grade'));
        $this->assertStringContainsString('CR 2022: upload the CR without grading.', implode(' ', $this->details()['sections']['cr']));

        $this->upload(EvaluationDocumentKind::CrWithoutGrading, 2022)->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('CR 2022', implode(' ', $this->details()['sections']['cr']));
    }

    public function test_a_cr_document_needs_the_no_duplicate_undertaking(): void
    {
        $this->saveCr(2023, [self::report('क')]);

        $this->upload(EvaluationDocumentKind::CrReport, 2023, false)->assertSessionHasErrors('cr');
        $this->assertSame(0, DpcEvaluationDocument::query()->count());

        $this->upload(EvaluationDocumentKind::CrReport, 2023, true)->assertSessionHasNoErrors();
        $this->assertNotNull(DpcEvaluationDocument::query()->sole()->undertaking_confirmed_at);
    }

    public function test_the_cr_section_is_complete_with_its_documents_and_the_channel_undertaking(): void
    {
        $this->saveCr(2023, [self::report('क')]);
        $this->saveCr(2024, [self::report('क+')]);

        $this->upload(EvaluationDocumentKind::CrReport, 2023);
        $this->upload(EvaluationDocumentKind::CrReport, 2024);
        $this->assertSame(['Give the undertaking that the CRs are signed as per channel and upload the channel document.'], $this->details()['sections']['cr']);

        $this->upload(EvaluationDocumentKind::CrChannel, null, false)->assertSessionHasErrors('cr');
        $this->assertNull($this->row->refresh()->cr_channel_undertaking_at, 'No undertaking without the tick.');

        $this->upload(EvaluationDocumentKind::CrChannel)->assertSessionHasNoErrors();

        $this->assertSame([], $this->details()['sections']['cr']);
        $this->assertNotNull($this->row->refresh()->cr_channel_undertaking_at);
    }

    public function test_a_cr_year_after_the_dpc_year_is_refused(): void
    {
        $this->saveCr(2027, [self::report('क')])->assertSessionHasErrors('cr');
        $this->assertSame(0, $this->row->crGrades()->count());
    }

    public function test_cr_data_of_one_proposal_does_not_reach_another(): void
    {
        $this->saveCr(2023, [self::report('क')]);

        $other = $this->raiseDpc($this->ao, $this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id);
        app(DpcProposalService::class)->saveSeniorityEntries($other, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01'],
        ], $this->ao, $other->seniorityReferenceDate());

        $this->assertSame(0, $other->evaluationRows()->sole()->crGrades()->count());
    }

    public function test_saving_the_evaluation_chart_keeps_the_cr_questionnaire_unless_the_grade_changes(): void
    {
        $this->saveCr(2023, [self::report('क')]);
        $this->saveCr(2024, [self::report('क')]);

        $chart = fn (string $grade2024): array => [
            ['sn' => 1, 'employee_number' => 'E-1', 'cr_grades' => [['year' => 2023, 'grade' => 'क'], ['year' => 2024, 'grade' => $grade2024]]],
            ['sn' => 2, 'employee_number' => 'E-2'],
        ];

        app(DpcProposalService::class)->saveEvaluationRows($this->proposal, $chart('क'), $this->ao);
        $this->assertTrue($this->row->crGrades()->where('year', 2024)->sole()->put_up, 'An unchanged grade keeps its answers.');

        app(DpcProposalService::class)->saveEvaluationRows($this->proposal, $chart('ग'), $this->ao);
        $changed = $this->row->crGrades()->where('year', 2024)->sole();
        $this->assertNull($changed->put_up, 'A grade changed on the chart must be answered again.');
        $this->assertNull($changed->reports);
        $this->assertTrue($this->row->crGrades()->where('year', 2023)->sole()->put_up);
    }

    // ========================================================= Property Return

    public function test_a_submitted_return_is_complete_with_its_dates_and_document(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2024-01-20', 'next_increment_date' => '2024-07-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Property Return 2023: upload the return.'], $this->details()['sections']['property']);

        $this->upload(EvaluationDocumentKind::PropertyReturn, 2023)->assertSessionHasNoErrors();

        $this->assertSame([], $this->details()['sections']['property']);
        $this->assertTrue((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'));
    }

    public function test_a_return_not_submitted_is_recorded_as_such(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), ['year' => 2023, 'submitted' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'));
        $this->assertSame([], $this->details()['sections']['property'], 'Recorded; the engine judges it.');
    }

    public function test_a_late_return_needs_the_departments_decision(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2024-03-01', 'next_increment_date' => '2024-07-01',
        ])->assertSessionHasErrors('late_decision');

        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2024-03-01', 'next_increment_date' => '2024-07-01',
            'late_decision' => 'rejected',
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'), 'A late return not accepted does not count.');

        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2024-03-01', 'next_increment_date' => '2024-07-01',
            'late_decision' => 'accepted', 'late_decision_remarks' => 'Officer was on election duty.',
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'));
        $this->assertContains('Property Return 2023: accepted late — record the reason and upload the decision.', $this->details()['sections']['property']);
    }

    public function test_the_same_next_increment_date_for_two_years_is_refused(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2022, 'submitted' => '1', 'submitted_on' => '2023-01-20', 'next_increment_date' => '2023-07-01',
        ]);

        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2024-01-20', 'next_increment_date' => '2023-07-01',
        ])->assertSessionHasErrors('property');

        $this->assertSame(1, $this->row->propertyFlags()->count());
    }

    public function test_the_same_ipr_file_for_two_years_is_refused(): void
    {
        foreach ([2022, 2023] as $year) {
            $this->actingAs($this->ao)->post($this->route('property.save'), [
                'year' => $year, 'submitted' => '1', 'submitted_on' => ($year + 1).'-01-20', 'next_increment_date' => ($year + 1).'-07-01',
            ]);
        }

        $file = UploadedFile::fake()->createWithContent('ipr.pdf', '%PDF-1.4 same bytes');
        $this->actingAs($this->ao)->post($this->route('documents.store'), ['verified' => '1', 'kind' => 'property_return', 'year' => 2022, 'document' => $file])
            ->assertSessionHasNoErrors();

        $again = UploadedFile::fake()->createWithContent('ipr-copy.pdf', '%PDF-1.4 same bytes');
        $this->actingAs($this->ao)->post($this->route('documents.store'), ['verified' => '1', 'kind' => 'property_return', 'year' => 2023, 'document' => $again])
            ->assertSessionHasErrors('property');
    }

    // ===================================================================== PH

    /**
     * @param  array<string, string>  $answers
     */
    private function answerPh(array $answers): PhReservationOutcome
    {
        $this->actingAs($this->ao)->put($this->route('ph'), $answers)->assertSessionHasNoErrors();

        return $this->row->refresh()->phOutcome();
    }

    public function test_case_1_a_non_ph_officer_gets_no_ph_reservation_and_no_further_questions(): void
    {
        // Later answers posted by the form are discarded, not kept.
        $outcome = $this->answerPh(['is_ph' => '0', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']);

        $this->assertSame(PhReservationOutcome::NotPh, $outcome);
        $this->assertNull($this->row->ph_previously_availed);
        $this->assertNull($this->row->ph_claims_reservation);
        $this->assertSame('no_benefit', $this->row->ph_final_decision);
        $this->assertNull($this->row->ph_verification_status);
        $this->assertSame([], $this->details()['sections']['ph'], 'No undertaking is asked of a non-PH officer.');
    }

    public function test_case_2_a_benefit_availed_before_gives_no_fresh_benefit_and_needs_the_document(): void
    {
        $outcome = $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '1', 'ph_claims_reservation' => '1']);

        $this->assertSame(PhReservationOutcome::PreviouslyAvailed, $outcome);
        $this->assertNull($this->row->ph_claims_reservation, 'No fresh claim can be recorded.');
        $this->assertNotNull($this->row->ph_ineligibility_reason);
        $this->assertSame('no_benefit', $this->row->ph_final_decision);

        // Every PH employee needs one supporting declaration/document (2026-10-19).
        $this->assertSame([__('dpc.employee_details.missing.ph_undertaking')], $this->details()['sections']['ph']);
        $this->upload(EvaluationDocumentKind::PhUndertaking)->assertSessionHasNoErrors();
        $this->assertSame([], $this->details()['sections']['ph']);
        $this->assertSame('no_benefit', $this->row->refresh()->ph_final_decision, 'The document grants nothing.');
    }

    public function test_case_3_a_declined_benefit_gives_no_reservation_and_needs_the_undertaking(): void
    {
        $outcome = $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '0']);

        $this->assertSame(PhReservationOutcome::Declined, $outcome);
        $this->assertSame('no_benefit', $this->row->ph_final_decision);
        $this->assertSame([__('dpc.employee_details.missing.ph_undertaking')], $this->details()['sections']['ph']);

        $this->upload(EvaluationDocumentKind::PhUndertaking)->assertSessionHasNoErrors();
        $this->assertSame([], $this->details()['sections']['ph']);
    }

    public function test_case_4_a_claim_is_recorded_but_not_approved_and_needs_the_undertaking(): void
    {
        $outcome = $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']);

        $this->assertSame(PhReservationOutcome::Claimed, $outcome);
        $this->assertTrue($this->row->ph_claims_reservation);
        $this->assertSame('pending', $this->row->ph_verification_status, 'A claim goes to eligibility verification.');
        $this->assertNull($this->row->ph_final_decision, 'A claim is not an approval.');
        $this->assertSame($this->ao->id, $this->row->ph_recorded_by);
        $this->assertSame([__('dpc.employee_details.missing.ph_undertaking')], $this->details()['sections']['ph']);

        $this->upload(EvaluationDocumentKind::PhUndertaking)->assertSessionHasNoErrors();
        $this->assertSame([], $this->details()['sections']['ph']);
        $this->assertNull($this->row->refresh()->ph_final_decision, 'Uploading the undertaking does not approve the claim either.');
    }

    public function test_the_ph_quota_is_three_percent_taking_the_whole_number_only(): void
    {
        $vacancyType = VacancyType::query()->firstOrFail();
        $category = ReservationCategory::query()->firstOrFail();
        $quota = fn (): array => app(EmployeeDetailsService::class)->phQuota($this->proposal->refresh());

        // 33 × 3% = 0.99 — not rounded up (rule 2.1: "even if it is .9").
        $this->proposal->vacancies()->create(['vacancy_type_id' => $vacancyType->id, 'reservation_category_id' => $category->id, 'count' => 33]);
        $this->assertSame(0, $quota()['posts']);

        // 67 × 3% = 2.01 → 2.
        $this->proposal->vacancies()->update(['count' => 67]);
        $this->assertSame(2, $quota()['posts']);
    }

    private function phCategory(): ReservationCategory
    {
        return ReservationCategory::query()->where('code', 'PH')->firstOrFail();
    }

    public function test_the_ph_quota_follows_the_ph_reservation_policy(): void
    {
        $vacancyType = VacancyType::query()->firstOrFail();
        $category = ReservationCategory::query()->firstOrFail();
        $quota = fn (): array => app(EmployeeDetailsService::class)->phQuota($this->proposal->refresh());

        $this->proposal->vacancies()->create(['vacancy_type_id' => $vacancyType->id, 'reservation_category_id' => $category->id, 'count' => 100]);
        $this->assertSame(3, $quota()['posts']);

        ReservationPolicy::query()->where('reservation_category_id', $this->phCategory()->id)->update(['status' => 'inactive']);
        ReservationPolicy::query()->create([
            'reservation_category_id' => $this->phCategory()->id,
            'percentage' => '4.50',
            'rounding' => 'floor',
            'effective_from' => $this->proposal->proposal_date->copy()->subYear()->toDateString(),
            'status' => 'active',
        ]);

        // 4.5% of 100 = 4.5 → 4, the decimal dropped.
        $this->assertSame(4, $quota()['posts']);
        $this->assertSame('4.5', $quota()['percent']);
    }

    public function test_an_earlier_ph_record_of_the_same_officer_is_shown_not_applied(): void
    {
        $earlier = $this->raiseDpc($this->ao, $this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id);
        app(DpcProposalService::class)->saveSeniorityEntries($earlier, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01'],
        ], $this->ao, $earlier->seniorityReferenceDate());
        $earlier->evaluationRows()->sole()->forceFill(['is_ph' => true, 'ph_previously_availed' => false, 'ph_claims_reservation' => true])->save();

        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('has a PH record on another DPC proposal')
            ->assertSee('PH reservation claimed');

        // The answer given here still stands — the record is for the verifier.
        $this->assertSame(PhReservationOutcome::Claimed, $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']));
    }

    public function test_the_ph_undertaking_needs_the_officers_confirmation(): void
    {
        $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']);

        $this->upload(EvaluationDocumentKind::PhUndertaking, null, false)->assertSessionHasErrors('ph');
        $this->assertSame(0, DpcEvaluationDocument::query()->count());
    }

    public function test_a_ph_undertaking_is_refused_where_none_applies(): void
    {
        $this->answerPh(['is_ph' => '0']);

        $this->upload(EvaluationDocumentKind::PhUndertaking)->assertSessionHasErrors('ph');
        $this->assertSame(0, DpcEvaluationDocument::query()->count());
    }

    public function test_an_invalid_document_is_rejected(): void
    {
        $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']);

        $this->actingAs($this->ao)->post($this->route('documents.store'), ['verified' => '1',
            'kind' => 'ph_undertaking', 'undertaking' => '1',
            'document' => UploadedFile::fake()->create('undertaking.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');

        $this->actingAs($this->ao)->post($this->route('documents.store'), ['verified' => '1',
            'kind' => 'ph_undertaking', 'undertaking' => '1',
            'document' => $this->pdf('big.pdf', (int) config('dpc.documents.max_kb') + 1),
        ])->assertSessionHasErrors('document');

        $this->assertSame(0, DpcEvaluationDocument::query()->count());
    }

    public function test_a_replaced_undertaking_is_superseded_not_deleted(): void
    {
        $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '0']);

        $this->upload(EvaluationDocumentKind::PhUndertaking);
        $first = DpcEvaluationDocument::query()->sole();
        $this->upload(EvaluationDocumentKind::PhUndertaking);

        $this->assertSame(2, DpcEvaluationDocument::query()->count());
        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertNotNull($first->superseded_by_id);
        Storage::disk($first->disk)->assertExists($first->path);

        $current = DpcEvaluationDocument::query()->whereNull('superseded_at')->sole();
        $this->assertSame($this->ao->id, $current->uploaded_by);
        $this->assertNotNull($current->uploaded_at);
        $this->assertSame(64, strlen($current->sha256));
        $this->assertStringStartsWith('private/employee-documents/', $current->path);

        $this->assertDatabaseHas('activity_timelines', ['event' => 'dpc_proposal.employee_document_uploaded', 'subject_id' => $this->proposal->id]);
    }

    public function test_the_document_downloads_for_the_department_only(): void
    {
        $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']);
        $this->upload(EvaluationDocumentKind::PhUndertaking);
        $document = DpcEvaluationDocument::query()->sole();
        $url = route('dpc-proposals.employees.documents.download', [$this->proposal, $this->row, $document]);

        $this->actingAs($this->ao)->get($url)->assertOk();
        $this->actingAs($this->ah)->get($url)->assertOk();

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();
        $this->actingAs($intruder)->get($url)->assertForbidden();
    }

    // ========================================================= authorization

    public function test_the_administrative_head_may_view_but_not_change(): void
    {
        $this->actingAs($this->ah)->get($this->route('show'))->assertOk()->assertSee('Read only');
        $this->actingAs($this->ah)->put($this->route('ph'), ['is_ph' => '0'])->assertForbidden();
        $this->actingAs($this->ah)->post($this->route('cr.save'), ['year' => 2023, 'put_up' => '0'])->assertForbidden();
        $this->actingAs($this->ah)->post($this->route('property.save'), ['year' => 2023, 'submitted' => '0'])->assertForbidden();
        $this->actingAs($this->ah)->post($this->route('documents.store'), ['verified' => '1', 'kind' => 'other', 'remarks' => 'x', 'document' => $this->pdf()])->assertForbidden();

        $this->assertNull($this->row->refresh()->is_ph);
    }

    public function test_another_departments_officer_cannot_reach_the_details(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($intruder)->get(route('dpc-proposals.employees.index', $this->proposal))->assertForbidden();
        $this->actingAs($intruder)->get($this->route('show'))->assertForbidden();
        $this->actingAs($intruder)->put($this->route('ph'), ['is_ph' => '0'])->assertForbidden();
        $this->actingAs($intruder)->post($this->route('documents.store'), ['verified' => '1', 'kind' => 'other', 'remarks' => 'x', 'document' => $this->pdf()])->assertForbidden();
    }

    public function test_other_roles_and_guests_are_refused(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $this->actingAs($admin)->get($this->route('show'))->assertForbidden();
        $this->actingAs($admin)->put($this->route('ph'), ['is_ph' => '0'])->assertForbidden();

        auth()->logout();
        $this->put($this->route('ph'), ['is_ph' => '0'])->assertRedirect();
        $this->assertNull($this->row->refresh()->is_ph);
    }

    public function test_an_officer_of_another_proposal_is_not_reachable_through_this_one(): void
    {
        $other = $this->raiseDpc($this->ao, $this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.employees.show', [$other, $this->row]))
            ->assertNotFound();
    }

    // ============================================== completeness and workflow

    public function test_an_incomplete_officer_blocks_submission(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->assertTrue(app(DpcProposalCompletenessService::class)->isComplete($this->proposal->refresh()));

        // One officer's PH answer withdrawn: the gate names the officer. (The
        // fixture may have sized the list to the zone, so the officer is read back.)
        $first = $this->proposal->evaluationRows()->orderBy('sn')->firstOrFail();
        $first->forceFill(['is_ph' => null])->save();

        $section = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->refresh()))->firstWhere('key', 'employee_details');
        $this->assertFalse($section['complete']);
        $this->assertStringContainsString("{$first->name} (PH Pending)", $section['detail']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->refresh()->status);
    }

    public function test_a_complete_proposal_submits_and_the_existing_approval_still_works(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);

        // Locked for the Authorized Officer once submitted.
        $this->actingAs($this->ao)->put($this->route('ph'), ['is_ph' => '1', 'ph_previously_availed' => '1'])->assertForbidden();

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->refresh()->status, 'The Administrative Head\'s stage is unchanged.');
    }

    public function test_the_ao_verifies_each_document_on_upload(): void
    {
        // Rule 4.4: the first of two verifications.
        $this->actingAs($this->ao)->post($this->route('documents.store'), [
            'kind' => 'other', 'remarks' => 'Service book extract', 'document' => $this->pdf(),
        ])->assertSessionHasErrors('verified');

        $this->upload(EvaluationDocumentKind::Other, null, true, ['remarks' => 'Service book extract'])->assertSessionHasNoErrors();

        $document = DpcEvaluationDocument::query()->sole();
        $this->assertSame($this->ao->id, $document->ao_verified_by);
        $this->assertNull($document->ah_verified_at, 'The Administrative Head\'s verification comes later.');
    }

    public function test_the_administrative_head_verifies_every_document_before_approving(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();

        // One document still waits for the AH.
        $document = DpcEvaluationDocument::query()->where('dpc_proposal_id', $this->proposal->id)->current()->firstOrFail();
        $document->forceFill(['ah_verified_at' => null, 'ah_verified_by' => null])->save();
        $row = $document->row;
        $route = fn (string $name, array $extra = []): string => route("dpc-proposals.employees.{$name}", [$this->proposal, $row, ...$extra]);

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))
            ->assertSessionHasErrors();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);

        // The AO cannot give the second verification.
        $this->actingAs($this->ao)->post($route('documents.verify', [$document]))->assertForbidden();

        $this->actingAs($this->ah)->get($route('show'))
            ->assertOk()
            ->assertSee('id="verify-all-documents"', false);
        $this->actingAs($this->ah)->post($route('documents.verify-all'))->assertSessionHasNoErrors();
        $this->assertSame($this->ah->id, $document->refresh()->ah_verified_by);

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->refresh()->status);
    }

    public function test_the_administrative_head_decides_every_ph_claim_before_approving(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $row = $this->proposal->evaluationRows()->orderBy('sn')->firstOrFail();
        $row->forceFill([
            'is_ph' => true, 'ph_previously_availed' => false, 'ph_claims_reservation' => true,
            'ph_verification_status' => 'pending', 'ph_final_decision' => null,
        ])->save();
        // The PH declaration every PH employee needs, verified twice.
        DpcEvaluationDocument::query()->create([
            'dpc_proposal_id' => $this->proposal->id, 'dpc_evaluation_row_id' => $row->id, 'employee_number' => $row->employee_number,
            'kind' => EvaluationDocumentKind::PhUndertaking->value, 'undertaking_confirmed_at' => now(),
            'disk' => 'local', 'path' => 'ph.pdf', 'original_name' => 'ph.pdf', 'size_bytes' => 1, 'sha256' => str_repeat('b', 64),
            'uploaded_by' => $this->ao->id, 'uploaded_at' => now(), 'ao_verified_at' => now(), 'ah_verified_at' => now(),
        ]);
        // A new document makes the original-documents undertaking stale; it is given again.
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();

        // Rule 2.1: not approved while a claim waits.
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasErrors();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);

        $verify = route('dpc-proposals.employees.ph.verify', [$this->proposal, $row]);
        $this->actingAs($this->ao)->post($verify, ['decision' => 'verified'])->assertForbidden();
        $this->actingAs($this->ah)->post($verify, ['decision' => 'not_verified'])->assertSessionHasErrors('remarks');
        $this->actingAs($this->ah)->post($verify, ['decision' => 'verified', 'remarks' => 'Certificate checked.'])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame(['verified', 'benefit', $this->ah->id], [$row->ph_verification_status, $row->ph_final_decision, $row->ph_verified_by]);
        $this->assertTrue($row->holdsVerifiedPhClaim());

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->refresh()->status);
    }

    public function test_the_section_can_be_stood_down(): void
    {
        config(['dpc.employee_details.required_for_submission' => false]);

        $keys = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal))->pluck('key')->all();

        $this->assertNotContains('employee_details', $keys);
    }

    // ======================================== CR points against the Class Master rule

    /**
     * A Class I → Class I rule in force for the 2026 DPC: the two CR years 2025 and
     * 2024, each mandatory with a minimum.
     */
    private function promotionRule(int $min = 5, int $total = 12, int $loyaltyYears = 0, int $propertyYears = 0): PromotionCrRule
    {
        $rule = PromotionCrRule::query()->create([
            'to_class' => 'I', 'from_class' => 'I', 'effective_from_year' => 2026,
            'cr_years' => 2, 'loyalty_years' => $loyaltyYears, 'property_years' => $propertyYears,
            'qualifying_total' => $total, 'qualifying_average' => $total / 2, 'super_seat_grade' => 9, 'tribal_bonus' => 1,
        ]);

        foreach ([1, 2] as $before) {
            $rule->years()->create([
                'years_before' => $before, 'cr_min_marks' => $min, 'cr_mandatory' => true,
                'loyalty_mandatory' => $before <= $loyaltyYears, 'property_mandatory' => $before <= $propertyYears,
            ]);
        }

        // The proposal is held to the rule fixed on it.
        $this->proposal->forceFill(['promotion_cr_rule_id' => $rule->id])->save();

        return $rule;
    }

    private function ruleCheck(): array
    {
        return app(PromotionRuleCheck::class)->check($this->row->refresh(), $this->proposal->refresh());
    }

    public function test_the_grade_choices_show_the_points_of_the_eligible_cr_grades(): void
    {
        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('क+ (9)')
            ->assertSee('क (7)')
            ->assertSee('घ (1)');
    }

    public function test_points_are_those_in_force_on_1_january_of_the_dpc_year(): void
    {
        // A later value for क, from 2027, does not reach a 2026 DPC.
        CrGradeValue::query()->create(['value' => 8, 'grade_code' => 'क', 'effective_from' => '2027-01-01']);

        $this->assertSame(7, app(PromotionCrRules::class)->pointsForYear(2026)['क']);
        $this->assertSame(8, app(PromotionCrRules::class)->pointsForYear(2027)['क']);
    }

    public function test_a_candidate_meeting_the_rule_is_shown_as_meeting_it(): void
    {
        $this->promotionRule();
        $this->saveCr(2025, [self::report('क')])->assertSessionHasNoErrors();
        $this->saveCr(2024, [self::report('क+')])->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('meets', $check['status']);
        $this->assertSame([7, 9], array_column($check['years'], 'points'));
        $this->assertSame(16, $check['total']);

        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('id="promotion-rule-check"', false)
            ->assertSee('data-rule-status="meets"', false);

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.index', $this->proposal))
            ->assertOk()
            ->assertSee('Meets rule');
    }

    public function test_the_highest_available_authority_counts_within_a_cr(): void
    {
        $this->promotionRule(min: 1, total: 2);
        // Accepting क+ over a Reporting घ; without an Accepting remark the Reviewing one.
        $this->saveCr(2025, [array_merge(self::report('घ'), ['accepting_grade' => 'क+'])])->assertSessionHasNoErrors();
        $this->saveCr(2024, [array_merge(self::report('घ'), ['reviewer_grade' => 'ख', 'accepting_designation' => null, 'accepting_grade' => null])]);

        $this->assertSame([9, 5], array_column($this->ruleCheck()['years'], 'points'));
    }

    public function test_several_crs_in_a_year_are_averaged_with_the_decimal_dropped(): void
    {
        $this->promotionRule(min: 1, total: 2);
        // क+ (9) and ग (3): 6. क+ (9) and क (7) and ग (3): 19 / 3 → 6.
        $this->saveCr(2025, [self::report('क+'), self::report('ग')])->assertSessionHasNoErrors();
        $this->saveCr(2024, [self::report('क+'), self::report('क'), self::report('ग')])->assertSessionHasNoErrors();

        $this->assertSame([6, 6], array_column($this->ruleCheck()['years'], 'points'));
    }

    public function test_a_cr_below_the_years_minimum_does_not_meet_the_rule(): void
    {
        $this->promotionRule(min: 7, total: 2);
        $this->saveCr(2025, [self::report('ख')])->assertSessionHasNoErrors();
        $this->saveCr(2024, [self::report('क')])->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.cr_below_min', ['year' => 2025, 'points' => 5, 'min' => 7]), $check['findings']);

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.index', $this->proposal))
            ->assertSee('Does not meet rule');
    }

    public function test_a_missing_mandatory_cr_year_does_not_meet_the_rule(): void
    {
        $this->promotionRule();
        $this->saveCr(2025, [self::report('क')])->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.cr_missing', ['year' => 2024]), $check['findings']);
    }

    public function test_a_total_below_the_qualifying_total_does_not_meet_the_rule(): void
    {
        $this->promotionRule(min: 1, total: 16);
        $this->saveCr(2025, [self::report('क')])->assertSessionHasNoErrors();
        $this->saveCr(2024, [self::report('क')])->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.total_below', ['total' => 14, 'required' => 16]), $check['findings']);
    }

    public function test_a_mandatory_property_return_is_checked(): void
    {
        $this->promotionRule(min: 1, total: 2, propertyYears: 1);
        $this->saveCr(2025, [self::report('क')])->assertSessionHasNoErrors();
        $this->saveCr(2024, [self::report('क')])->assertSessionHasNoErrors();

        $this->assertContains(__('dpc.employee_details.rule.property_missing', ['year' => 2025]), $this->ruleCheck()['findings']);
    }

    public function test_without_a_class_master_rule_the_check_says_so(): void
    {
        // A proposal raised before rules were fixed on it, with no rule in force.
        $this->proposal->forceFill(['promotion_cr_rule_id' => null])->save();
        PromotionCrRuleYear::query()->delete();
        PromotionCrRule::query()->delete();

        $check = $this->ruleCheck();
        $this->assertSame('no_rule', $check['status']);
        $this->assertNull($check['rule']);

        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('data-rule-status="no_rule"', false)
            ->assertSee(__('dpc.employee_details.rule.no_rule', ['from' => 'I', 'to' => 'I', 'year' => 2026]));
    }

    public function test_the_rule_check_does_not_change_what_is_required_to_submit(): void
    {
        $this->promotionRule(min: 9);

        // The rule is reported for the committee; completeness is unchanged.
        $this->assertSame($this->details()['complete'], app(EmployeeDetailsService::class)->status($this->row->refresh())['complete']);
        $this->actingAs($this->ao)->get($this->route('show'))->assertOk()->assertSee('data-rule-status="does_not_meet"', false);
    }
}
