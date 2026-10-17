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
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
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
        return $this->actingAs($this->ao)->post($this->route('documents.store'), array_merge([
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
        $this->actingAs($this->ao)->post($this->route('documents.store'), ['kind' => 'property_return', 'year' => 2022, 'document' => $file])
            ->assertSessionHasNoErrors();

        $again = UploadedFile::fake()->createWithContent('ipr-copy.pdf', '%PDF-1.4 same bytes');
        $this->actingAs($this->ao)->post($this->route('documents.store'), ['kind' => 'property_return', 'year' => 2023, 'document' => $again])
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

    public function test_case_2_a_benefit_availed_before_gives_no_fresh_benefit(): void
    {
        $outcome = $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '1', 'ph_claims_reservation' => '1']);

        $this->assertSame(PhReservationOutcome::PreviouslyAvailed, $outcome);
        $this->assertNull($this->row->ph_claims_reservation, 'No fresh claim can be recorded.');
        $this->assertNotNull($this->row->ph_ineligibility_reason);
        $this->assertSame('no_benefit', $this->row->ph_final_decision);
        $this->assertSame([], $this->details()['sections']['ph']);
    }

    public function test_case_3_a_declined_benefit_gives_no_reservation_and_needs_the_undertaking(): void
    {
        $outcome = $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '0']);

        $this->assertSame(PhReservationOutcome::Declined, $outcome);
        $this->assertSame('no_benefit', $this->row->ph_final_decision);
        $this->assertSame(["Upload the employee's PH undertaking."], $this->details()['sections']['ph']);

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
        $this->assertSame(["Upload the employee's PH undertaking."], $this->details()['sections']['ph']);

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

        $this->actingAs($this->ao)->post($this->route('documents.store'), [
            'kind' => 'ph_undertaking', 'undertaking' => '1',
            'document' => UploadedFile::fake()->create('undertaking.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');

        $this->actingAs($this->ao)->post($this->route('documents.store'), [
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
        $this->actingAs($this->ah)->post($this->route('documents.store'), ['kind' => 'other', 'remarks' => 'x', 'document' => $this->pdf()])->assertForbidden();

        $this->assertNull($this->row->refresh()->is_ph);
    }

    public function test_another_departments_officer_cannot_reach_the_details(): void
    {
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($intruder)->get(route('dpc-proposals.employees.index', $this->proposal))->assertForbidden();
        $this->actingAs($intruder)->get($this->route('show'))->assertForbidden();
        $this->actingAs($intruder)->put($this->route('ph'), ['is_ph' => '0'])->assertForbidden();
        $this->actingAs($intruder)->post($this->route('documents.store'), ['kind' => 'other', 'remarks' => 'x', 'document' => $this->pdf()])->assertForbidden();
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

    public function test_the_section_can_be_stood_down(): void
    {
        config(['dpc.employee_details.required_for_submission' => false]);

        $keys = collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal))->pluck('key')->all();

        $this->assertNotContains('employee_details', $keys);
    }
}
