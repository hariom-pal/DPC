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
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\PhType;
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
use Illuminate\Support\Facades\DB;
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

        // PH reservation applies to this DPC: two vacancies at 50% give one PH seat
        // (MoM of 12 November 2026 — the PH section is shown only where it applies).
        $this->givePhSeats(2, 50);
    }

    private function givePhSeats(int $vacancies, int $percent): void
    {
        DB::table('dpc_vacancies')->where('dpc_proposal_id', $this->proposal->id)->delete();
        if ($vacancies > 0) {
            DB::table('dpc_vacancies')->insert([
                'dpc_proposal_id' => $this->proposal->id,
                'vacancy_type_id' => DB::table('vacancy_types')->orderBy('id')->value('id'),
                'reservation_category_id' => DB::table('reservation_categories')->where('code', 'UR')->value('id'),
                'count' => $vacancies, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('reservation_policies')->whereIn('reservation_category_id', DB::table('reservation_categories')->where('code', 'PH')->pluck('id'))->update(['percentage' => $percent]);
        config(['dpc.employee_details.ph_quota_percent' => $percent]);
    }

    /**
     * A saved section opens read-only with an Edit button (MoM of 12 November 2026,
     * point 10); an unsaved one is open for entry.
     */
    public function test_a_saved_section_is_locked_until_edit(): void
    {
        $show = fn () => (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()->getContent();
        $lockedForm = fn (string $html, string $route): bool => (bool) preg_match('#<form method="POST" action="'.preg_quote(route($route, [$this->proposal, $this->row]), '#').'"[^>]*data-saved-lock#s', $html);

        $html = $show();
        $this->assertStringContainsString('js/saved-lock.js', $html);
        $this->assertFalse($lockedForm($html, 'dpc-proposals.employees.vigilance'), 'Not saved yet: open.');

        $this->actingAs($this->ao)->put(route('dpc-proposals.employees.vigilance', [$this->proposal, $this->row]), $this->vigilanceForm())->assertSessionHasNoErrors();

        $html = $show();
        $this->assertTrue($lockedForm($html, 'dpc-proposals.employees.vigilance'), 'Saved: locked until Edit.');
        $this->assertStringContainsString('data-label-edit="'.__('ui.edit').'"', $html);
    }

    /**
     * No PH seat in this DPC's vacancies: the PH Reservation section is neither
     * shown nor asked (MoM of 12 November 2026).
     */
    public function test_without_a_ph_seat_the_ph_section_is_neither_shown_nor_asked(): void
    {
        $this->givePhSeats(2, 3);
        $this->assertFalse(app(EmployeeDetailsService::class)->phApplies($this->proposal->fresh()));

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()
            ->assertDontSee('id="section-ph"', false);
        $this->get(route('dpc-proposals.employees.index', $this->proposal))->assertOk()->assertDontSee('id="ph-quota"', false);
        $this->assertSame([], app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['ph']);

        $this->givePhSeats(2, 50);
        $this->assertTrue(app(EmployeeDetailsService::class)->phApplies($this->proposal->fresh()));
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertSee('id="section-ph"', false);
        $this->assertNotSame([], app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['ph']);
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

    /** The Department Enquiry/Court Case section, nothing pending, each answer with its document (2026-11-14). */
    private function vigilanceForm(array $overrides = []): array
    {
        return [
            'enquiry_ongoing' => '0', 'punishment_effective' => '0',
            'enquiry_document' => $this->pdf('enquiry.pdf'), 'punishment_document' => $this->pdf('punishment.pdf'),
            'vigilance_verified' => '1', 'vigilance_pending' => '0', ...$overrides,
        ];
    }

    /** A Fit Integrity answer (2026-11-14). */
    private function fitIntegrity(): int
    {
        return (int) IntegrityOption::query()->where('fit', true)->orderBy('sort_order')->value('id');
    }

    /**
     * The Property Return items naming one year.
     *
     * @return list<string>
     */
    private function propertyItems(int $year): array
    {
        return array_values(array_filter($this->details()['sections']['property'], fn (string $item): bool => str_contains($item, (string) $year)));
    }

    private function details(): array
    {
        return app(EmployeeDetailsService::class)->status($this->row->refresh());
    }

    /**
     * One CR a year (2026-11-01): the authorities whose remarks it carries, and the
     * highest one's grade.
     *
     * @param  list<string>  $authorities
     */
    private function saveCr(int $year, string $grade, array $authorities = ['reporter', 'reviewer', 'accepting']): TestResponse
    {
        // A CR without every authority's remarks carries its NRC (MoM of 12 November 2026).
        // The NRC comes with its CR (2026-11-14).
        $nrc = count($authorities) < 3 ? ['nrc' => $this->pdf('nrc.pdf'), 'cr_document' => $this->pdf('cr.pdf'), 'cr_verified' => '1', 'cr_undertaking' => '1'] : [];

        return $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'authorities' => $authorities, 'grade' => $grade,
            'integrity_option_id' => $this->fitIntegrity(), ...$nrc,
        ]);
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
            // The posting date is the list's: posted, it changes nothing (2026-11-14).
            'date_of_posting_on_promotion_from_post' => '2015-06-01',
            // Rule 4.4: the service-record orders and the undertaking.
            'dob_order_number' => 'SR/1', 'dob_order_date' => '1995-07-01',
            'joining_order_number' => 'APPT/1', 'joining_order_date' => '1995-07-01',
            'service_record_undertaking' => '1',
        ])->assertSessionHasNoErrors();

        $this->saveCr(2023, 'क')->assertSessionHasNoErrors();

        // Resume: the screen shows what was saved, and only that section moved on.
        $this->actingAs($this->ao)->get($this->route('show'))
            ->assertOk()
            ->assertSee('SR/1', false)
            ->assertDontSee('name="date_of_posting_on_promotion_from_post"', false)
            ->assertSee('id="basic-from-csv"', false)
            ->assertSee('data-cr-year="2023"', false);
        $this->assertNotEquals('2015-06-01', $this->row->refresh()->date_of_posting_on_promotion_from_post?->toDateString());

        $status = $this->details();
        $this->assertSame([], $status['sections']['basic']);
        $this->assertNotSame([], $status['sections']['property']);
        $this->assertFalse($status['complete']);
    }

    // ===================================================================== CR

    public function test_one_cr_a_year_records_the_highest_authoritys_grade_and_whose_remarks_it_carries(): void
    {
        $this->saveCr(2023, 'क+')->assertSessionHasNoErrors();

        $cr = $this->row->crGrades()->where('year', 2023)->firstOrFail();
        $this->assertSame('क+', $cr->grade);
        $this->assertSame([['authorities' => ['reporter', 'reviewer', 'accepting'], 'authority' => 'accepting', 'grade' => 'क+']], $cr->reports);

        // The authorities and the grade are both needed for a graded CR.
        $this->saveCr(2022, 'क', [])->assertSessionHasErrors();
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter']])
            ->assertSessionHasErrors();
    }

    public function test_an_unticked_authority_needs_an_nrc_uploaded_with_the_cr(): void
    {
        // MoM of 12 November 2026: the NRC is uploaded there and then, or the CR is not saved.
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => 2023, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer'], 'grade' => 'क',
        ])->assertSessionHasErrors(['cr' => __('dpc.employee_details.cr.nrc_required')]);
        $this->assertNull($this->row->crGrades()->where('year', 2023)->first());

        $this->saveCr(2023, 'क', ['reporter', 'reviewer'])->assertSessionHasNoErrors();

        $this->assertSame('क', $this->row->crGrades()->where('year', 2023)->value('grade'));
        $this->assertSame(1, $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::CrNrc->value)->where('year', 2023)->count());
        $this->assertNotContains('CR 2023: a reporting, reviewing or accepting remark is missing — upload the NRC.', $this->details()['sections']['cr']);

        // Saved again, the NRC already on file is enough.
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => 2023, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter'], 'grade' => 'ख',
        ])->assertSessionHasNoErrors();
    }

    /**
     * A CR not put up is Missing (2026-11-05): its reason and supporting document are
     * required on the same form, recorded as the missing-record justification, and
     * no grade is invented for it.
     */
    public function test_a_cr_not_put_up_needs_its_reason_and_document(): void
    {
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '0'])
            ->assertSessionHasErrors(['missing_reason_id', 'missing_document']);
        $this->assertFalse($this->row->crGrades()->where('year', 2022)->exists(), 'Nothing is saved without the justification.');

        $this->post($this->route('cr.save'), [
            'year' => 2022, 'put_up' => '0',
            'missing_reason_id' => RecordReason::query()->where('code', 'nrc_issued')->value('id'),
            'missing_document' => $this->pdf('nrc.pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->row->crGrades()->where('year', 2022)->value('grade'), 'No grade is invented for a missing CR.');
        $gap = DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->where('kind', 'cr')->where('year', 2022)->sole();
        $this->assertTrue($gap->isJustified());
        $this->assertStringNotContainsString('CR 2022', implode(' ', $this->details()['sections']['cr']));

        // Saved again later, the year already justified is not asked again.
        $this->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '0'])->assertSessionHasNoErrors();
    }

    public function test_the_rule_3_2_reasons_are_offered(): void
    {
        foreach (['nrc_issued', 'long_leave', 'suspended'] as $code) {
            $this->assertTrue(RecordReason::query()->where('code', $code)->exists(), $code);
        }
    }

    public function test_a_cr_without_grading_needs_the_cr_uploaded(): void
    {
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '1', 'has_grading' => '0', 'integrity_option_id' => $this->fitIntegrity()])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->row->crGrades()->where('year', 2022)->value('grade'));
        $this->assertStringContainsString('CR 2022: upload the CR without grading.', implode(' ', $this->details()['sections']['cr']));

        $this->upload(EvaluationDocumentKind::CrWithoutGrading, 2022)->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('CR 2022', implode(' ', $this->details()['sections']['cr']));
    }

    public function test_a_cr_document_needs_the_no_duplicate_undertaking(): void
    {
        $this->saveCr(2023, 'क');

        $this->upload(EvaluationDocumentKind::CrReport, 2023, false)->assertSessionHasErrors('cr');
        $this->assertSame(0, DpcEvaluationDocument::query()->count());

        $this->upload(EvaluationDocumentKind::CrReport, 2023, true)->assertSessionHasNoErrors();
        $this->assertNotNull(DpcEvaluationDocument::query()->sole()->undertaking_confirmed_at);
    }

    /** 2026-11-14: the CR is uploaded on the same form as its grading. */
    public function test_the_cr_is_uploaded_with_its_grading(): void
    {
        $form = ['year' => 2023, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क', 'integrity_option_id' => $this->fitIntegrity()];

        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertSee('name="cr_document"', false);

        // Verified against the original and with the undertaking, or nothing is saved.
        $this->actingAs($this->ao)->post($this->route('cr.save'), [...$form, 'cr_document' => $this->pdf('cr.pdf')])
            ->assertSessionHasErrors(['cr_verified', 'cr_undertaking']);
        $this->assertSame(0, $this->row->crGrades()->count());
        $this->assertSame(0, DpcEvaluationDocument::query()->count());

        $this->post($this->route('cr.save'), [...$form, 'cr_document' => $this->pdf('cr.pdf'), 'cr_verified' => '1', 'cr_undertaking' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('क', $this->row->crGrades()->where('year', 2023)->value('grade'));
        $document = DpcEvaluationDocument::query()->sole();
        $this->assertSame(EvaluationDocumentKind::CrReport, $document->kind);
        $this->assertSame(2023, $document->year);
        $this->assertNotNull($document->undertaking_confirmed_at);
        $this->assertStringNotContainsString('CR 2023', implode(' ', $this->details()['sections']['cr']));

        // A year without grading takes the CR without grading; one not put up has no CR.
        $this->post($this->route('cr.save'), [
            'year' => 2022, 'put_up' => '1', 'has_grading' => '0', 'cr_document' => $this->pdf('cr.pdf'), 'cr_verified' => '1', 'cr_undertaking' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(EvaluationDocumentKind::CrWithoutGrading, DpcEvaluationDocument::query()->where('year', 2022)->sole()->kind);
    }

    /** 2026-11-14: no kind to choose — the year's answer says which CR it is. */
    public function test_the_cr_upload_takes_its_kind_from_the_year(): void
    {
        $this->saveCr(2023, 'क');
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => 2022, 'put_up' => '1', 'has_grading' => '0'])->assertSessionHasNoErrors();

        $page = $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]));
        $page->assertDontSee('id="cr-doc-kind"', false);

        // Sent as a CR, a year without grading is stored as the CR without grading.
        $this->upload(EvaluationDocumentKind::CrReport, 2022)->assertSessionHasNoErrors();
        $this->assertSame(EvaluationDocumentKind::CrWithoutGrading, DpcEvaluationDocument::query()->where('year', 2022)->sole()->kind);

        // A year whose CR was not put up has no CR to upload.
        $this->post($this->route('cr.save'), [
            'year' => 2021, 'put_up' => '0',
            'missing_reason_id' => RecordReason::query()->where('code', 'nrc_issued')->value('id'),
            'missing_document' => $this->pdf('nrc.pdf'),
        ])->assertSessionHasNoErrors();
        $this->upload(EvaluationDocumentKind::CrReport, 2021)->assertSessionHasErrors('cr');
    }

    /** 2026-11-14: the NRC certifies remarks missing from a CR, so the CR comes with it. */
    public function test_an_nrc_comes_with_its_cr(): void
    {
        $form = ['year' => 2023, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter'], 'grade' => 'क', 'nrc' => $this->pdf('nrc.pdf')];

        $this->actingAs($this->ao)->post($this->route('cr.save'), $form)
            ->assertSessionHasErrors(['cr' => __('dpc.employee_details.cr.nrc_needs_cr')]);
        $this->assertSame(0, $this->row->crGrades()->count());

        $this->post($this->route('cr.save'), [...$form, 'nrc' => $this->pdf('nrc.pdf'), 'cr_document' => $this->pdf('cr.pdf'), 'cr_verified' => '1', 'cr_undertaking' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, DpcEvaluationDocument::query()->where('year', 2023)->count());
    }

    /**
     * 2026-11-14: with the required CRs with remarks recorded — not counting a missing
     * year or one without grading — "Record a CR year" is no longer offered; a missing
     * year just before the DPC year leaves one fewer.
     */
    public function test_the_cr_form_closes_once_the_required_crs_are_recorded(): void
    {
        $dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
        $show = fn () => $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]));

        $this->post($this->route('cr.save'), [
            'year' => $dpcYear - 1, 'put_up' => '0',
            'missing_reason_id' => RecordReason::query()->where('code', 'long_leave')->value('id'),
            'missing_document' => $this->pdf('leave.pdf'),
        ])->assertSessionHasNoErrors();
        $this->post($this->route('cr.save'), ['year' => $dpcYear - 2, 'put_up' => '1', 'has_grading' => '0'])->assertSessionHasNoErrors();
        foreach (range($dpcYear - 3, $dpcYear - 5) as $year) {
            $this->saveCr($year, 'क')->assertSessionHasNoErrors();
        }
        $show()->assertSee('data-employee-cr-form', false);

        $this->saveCr($dpcYear - 6, 'क')->assertSessionHasNoErrors();
        $show()->assertDontSee('data-employee-cr-form', false)->assertSee('id="cr-years-complete"', false);

        // A recorded year is still changed with its Edit.
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row, 'cr_year' => $dpcYear - 3]))
            ->assertSee('data-employee-cr-form', false);

        // Without the missing year just before the DPC year, five are needed.
        $this->delete(route('dpc-proposals.employees.cr.remove', [$this->proposal, $this->row, $dpcYear - 1]));
        $show()->assertSee('data-employee-cr-form', false);
    }

    /** 2026-11-14: the last five years offered, the return uploaded with its year, the form closed once all five are saved. */
    public function test_the_property_form_offers_the_last_five_years_and_closes_when_saved(): void
    {
        $dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
        $html = (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->getContent();
        $select = substr($html, (int) strpos($html, 'id="property-year"'));
        $select = substr($select, 0, (int) strpos($select, '</select>'));
        foreach (range($dpcYear - 5, $dpcYear - 1) as $year) {
            $this->assertStringContainsString('<option value="'.$year.'"', $select);
        }
        $this->assertStringNotContainsString('<option value="'.($dpcYear - 6).'"', $select);
        $this->assertStringNotContainsString('<option value="'.$dpcYear.'"', $select);
        $this->assertSame(3, substr_count($html, 'data-show-if-late'), 'The late-return questions and document are asked only for a late return.');

        // The return uploaded with its year, verified against its original.
        $first = $dpcYear - 5;
        $form = ['year' => $first, 'submitted' => '1', 'submitted_on' => $first.'-01-10', 'next_increment_date' => ($first + 1).'-07-01', 'property_document' => $this->pdf('ipr.pdf')];
        $this->post($this->route('property.save'), $form)->assertSessionHasErrors('property_verified');
        $this->assertSame(0, $this->row->propertyFlags()->count());
        $this->post($this->route('property.save'), [...$form, 'property_document' => $this->pdf('ipr.pdf'), 'property_verified' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(EvaluationDocumentKind::PropertyReturn, DpcEvaluationDocument::query()->where('year', $first)->sole()->kind);

        foreach (range($dpcYear - 4, $dpcYear - 1) as $i => $year) {
            $this->post($this->route('property.save'), [
                'year' => $year, 'submitted' => '1', 'submitted_on' => $year.'-01-10', 'next_increment_date' => ($year + 1).'-07-0'.($i + 2),
            ])->assertSessionHasNoErrors();
        }

        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertDontSee('data-employee-property-form', false)->assertSee('id="property-years-complete"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row, 'property_year' => $first]))
            ->assertSee('data-employee-property-form', false);
    }

    public function test_the_cr_section_is_complete_with_its_documents_and_the_channel_undertaking(): void
    {
        $this->saveCr(2023, 'क');
        $this->saveCr(2024, 'क+');

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
        $this->saveCr(2027, 'क')->assertSessionHasErrors('cr');
        $this->assertSame(0, $this->row->crGrades()->count());
    }

    public function test_cr_data_of_one_proposal_does_not_reach_another(): void
    {
        $this->saveCr(2023, 'क');

        $other = $this->raiseDpc($this->ao, $this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id);
        app(DpcProposalService::class)->saveSeniorityEntries($other, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01'],
        ], $this->ao, $other->seniorityReferenceDate());

        $this->assertSame(0, $other->evaluationRows()->sole()->crGrades()->count());
    }

    public function test_saving_the_evaluation_chart_keeps_the_cr_questionnaire_unless_the_grade_changes(): void
    {
        $this->saveCr(2023, 'क');
        $this->saveCr(2024, 'क');

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
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2023-01-20', 'next_increment_date' => '2024-07-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Property Return 2023: upload the return.'], $this->propertyItems(2023));

        $this->upload(EvaluationDocumentKind::PropertyReturn, 2023)->assertSessionHasNoErrors();

        $this->assertSame([], $this->propertyItems(2023));
        $this->assertTrue((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'));
    }

    public function test_a_return_not_submitted_is_recorded_as_such(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), ['year' => 2023, 'submitted' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->row->propertyFlags()->where('year', 2023)->value('flag'));
        $this->assertSame([], $this->propertyItems(2023), 'Recorded; the engine judges it.');
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

    /**
     * Property Return (MoM of 12 November 2026): the last five years listed
     * continuously, recorded or not; a separate "Upload Late Property Return
     * Document" offering only the late years.
     */
    public function test_property_returns_list_five_years_and_late_uploads_offer_only_late_years(): void
    {
        $dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
        $late = $dpcYear - 3;
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => $late, 'submitted' => '1', 'submitted_on' => ($late + 1).'-03-01', 'next_increment_date' => ($late + 1).'-07-01',
            'late_decision' => 'accepted', 'late_decision_remarks' => 'Election duty.',
        ])->assertSessionHasNoErrors();
        // On time: by 31 January of its own year (2026-11-14).
        $onTime = $dpcYear - 2;
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => $onTime, 'submitted' => '1', 'submitted_on' => $onTime.'-01-15', 'next_increment_date' => ($onTime + 1).'-07-02',
        ])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk();
        foreach (range($dpcYear - 5, $dpcYear - 1) as $year) {
            $page->assertSee('data-property-year="'.$year.'"', false);
        }
        $page->assertSee('data-property-year="'.($dpcYear - 5).'" data-property-missing', false)
            ->assertDontSee('data-property-year="'.$late.'" data-property-missing', false)
            ->assertSee(__('dpc.employee_details.property.late_upload_heading'));

        // The late decision's document goes with its year — no separate upload (2026-11-14).
        $page->assertDontSee('id="property-late-upload"', false)->assertSee('data-property-late-document', false);
        $this->assertTrue($this->row->propertyFlags()->where('year', $late)->sole()->isLate());
        $this->assertFalse($this->row->propertyFlags()->where('year', $onTime)->sole()->isLate());

        // "Record" on a missing year opens the form on that year.
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row, 'property_year' => $dpcYear - 5]))
            ->assertSee('id="property-year" name="year" required', false)
            ->assertSee('<option value="'.($dpcYear - 5).'" selected', false);
    }

    public function test_the_same_next_increment_date_for_two_years_is_refused(): void
    {
        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2022, 'submitted' => '1', 'submitted_on' => '2022-01-20', 'next_increment_date' => '2023-07-01',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => 2023, 'submitted' => '1', 'submitted_on' => '2023-01-20', 'next_increment_date' => '2023-07-01',
        ])->assertSessionHasErrors('property');

        $this->assertSame(1, $this->row->propertyFlags()->count());
    }

    public function test_the_same_ipr_file_for_two_years_is_refused(): void
    {
        foreach ([2022, 2023] as $year) {
            $this->actingAs($this->ao)->post($this->route('property.save'), [
                'year' => $year, 'submitted' => '1', 'submitted_on' => $year.'-01-20', 'next_increment_date' => ($year + 1).'-07-01',
            ])->assertSessionHasNoErrors();
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
        // A PH officer's type, from the PH Type master (2026-11-05).
        if (($answers['is_ph'] ?? '') === '1') {
            $answers['ph_type_id'] ??= (string) PhType::query()->where('code', 'OL')->value('id');
        }
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
        $this->givePhSeats(0, 3);

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
        $this->givePhSeats(0, 3);

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

        // Opened in the browser, not saved (MoM of 12 November 2026).
        $this->assertStringStartsWith('inline;', (string) $this->actingAs($this->ao)->get($url)->assertOk()->headers->get('Content-Disposition'));
        $this->actingAs($this->ah)->get($url)->assertOk();
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()
            ->assertSee('data-uploaded-document="'.$document->uuid.'"', false)
            ->assertSee(__('dpc.employee_details.documents.uploaded'))
            ->assertSee('target="_blank"', false);

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
        // PH applies to this DPC, so its answer is asked.
        DB::table('reservation_policies')->whereIn('reservation_category_id', DB::table('reservation_categories')->where('code', 'PH')->pluck('id'))->update(['percentage' => 100]);
        config(['dpc.employee_details.ph_quota_percent' => 100]);

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

        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->refresh()->status, 'The Administrative Head\'s stage is unchanged.');
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
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->refresh()->status);
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
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->refresh()->status);
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
        $this->saveCr(2025, 'क')->assertSessionHasNoErrors();
        $this->saveCr(2024, 'क+')->assertSessionHasNoErrors();

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
            ->assertSee('FIT');
    }

    public function test_the_highest_available_authority_counts_within_a_cr(): void
    {
        $this->promotionRule(min: 1, total: 2);
        // The grade of the highest authority on the CR, whichever it is.
        $this->saveCr(2025, 'क+')->assertSessionHasNoErrors();
        $this->saveCr(2024, 'ख', ['reporter', 'reviewer']);

        $this->assertSame([9, 5], array_column($this->ruleCheck()['years'], 'points'));
    }

    /**
     * Several CRs in a year (MoM of 12 November 2026): up to four; one graded counts as
     * it is; several are averaged and taken down to the nearest grade at or below.
     */
    public function test_several_crs_in_a_year_are_averaged_down_to_the_nearest_grade(): void
    {
        $this->promotionRule(min: 1, total: 2);
        $all = ['reporter', 'reviewer', 'accepting'];
        $save = fn (int $year, array $reports, int $count) => $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'cr_count' => $count, 'reports' => $reports,
        ]);

        // क+ (9) and क (7): average 8, taken down to क (7) — never up to क+.
        $save(2025, [['authorities' => $all, 'grade' => 'क+'], ['authorities' => $all, 'grade' => 'क']], 2)->assertSessionHasNoErrors();
        $this->assertSame('क', $this->row->crGrades()->where('year', 2025)->value('grade'));

        // Two CRs, only one graded: that grade.
        $save(2024, [['authorities' => $all, 'grade' => 'ग'], ['authorities' => $all, 'grade' => '']], 2)->assertSessionHasNoErrors();
        $this->assertSame('ग', $this->row->crGrades()->where('year', 2024)->value('grade'));
        $this->assertSame([7, 3], array_column($this->ruleCheck()['years'], 'points'), 'The engine reads the same grades.');

        // Four at most; a fifth is refused.
        $five = array_fill(0, 5, ['authorities' => $all, 'grade' => 'क']);
        $save(2023, $five, 4)->assertSessionHasErrors('reports');

        // Graded, but no grade at all: refused.
        $save(2022, [['authorities' => $all, 'grade' => '']], 1)->assertSessionHasErrors(['cr' => __('dpc.employee_details.cr.reports_required')]);
    }

    /** The DPC year is not a CR year; the year list stops at the year before it and drops recorded years. */
    public function test_the_cr_year_list_stops_before_the_dpc_year_and_drops_recorded_years(): void
    {
        $dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
        $this->saveCr($dpcYear, 'क')->assertSessionHasErrors(['cr' => __('dpc.employee_details.cr.year_before_dpc_year', ['year' => $dpcYear - 1])]);
        $this->saveCr($dpcYear - 1, 'क')->assertSessionHasNoErrors();

        $html = (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()->getContent();
        $select = substr($html, (int) strpos($html, 'id="cr-year"'));
        $select = substr($select, 0, (int) strpos($select, '</select>'));
        $this->assertStringNotContainsString('<option value="'.$dpcYear.'"', $select);
        $this->assertStringNotContainsString('<option value="'.($dpcYear - 1).'"', $select, 'Recorded: changed with its Edit.');
        $this->assertStringContainsString('<option value="'.($dpcYear - 2).'"', $select);
        $this->assertStringContainsString('js/forms/cr-reports.js', $html);

        // Only the five-year window is offered (2026-11-14), and the CR goes with its year — no separate upload.
        $this->assertStringContainsString('<option value="'.($dpcYear - 5).'"', $select);
        $this->assertStringNotContainsString('<option value="'.($dpcYear - 6).'"', $select);
        $this->assertStringNotContainsString('id="cr-upload"', $html);
    }

    public function test_a_cr_below_the_years_minimum_does_not_meet_the_rule(): void
    {
        $this->promotionRule(min: 7, total: 2);
        $this->saveCr(2025, 'ख')->assertSessionHasNoErrors();
        $this->saveCr(2024, 'क')->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.cr_below_min', ['year' => 2025, 'points' => 5, 'min' => 7]), $check['findings']);

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.index', $this->proposal))
            ->assertSee('Unfit');
    }

    public function test_a_missing_mandatory_cr_year_does_not_meet_the_rule(): void
    {
        $this->promotionRule();
        $this->saveCr(2025, 'क')->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.cr_missing', ['year' => 2024]), $check['findings']);
    }

    public function test_a_total_below_the_qualifying_total_does_not_meet_the_rule(): void
    {
        $this->promotionRule(min: 1, total: 16);
        $this->saveCr(2025, 'क')->assertSessionHasNoErrors();
        $this->saveCr(2024, 'क')->assertSessionHasNoErrors();

        $check = $this->ruleCheck();
        $this->assertSame('does_not_meet', $check['status']);
        $this->assertContains(__('dpc.employee_details.rule.total_below', ['total' => 14, 'required' => 16]), $check['findings']);
    }

    public function test_a_mandatory_property_return_is_checked(): void
    {
        $this->promotionRule(min: 1, total: 2, propertyYears: 1);
        $this->saveCr(2025, 'क')->assertSessionHasNoErrors();
        $this->saveCr(2024, 'क')->assertSessionHasNoErrors();

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

    /** A PH officer's type comes from the PH Type master (2026-11-05). */
    public function test_a_ph_officer_needs_a_ph_type_from_the_master(): void
    {
        $this->actingAs($this->ao)->put($this->route('ph'), ['is_ph' => '1', 'ph_previously_availed' => '1'])
            ->assertSessionHasErrors('ph_type_id');

        $withdrawn = PhType::query()->where('code', 'MW')->sole();
        $withdrawn->forceFill(['status' => 'inactive'])->save();
        $this->put($this->route('ph'), ['is_ph' => '1', 'ph_previously_availed' => '1', 'ph_type_id' => (string) $withdrawn->id])
            ->assertSessionHasErrors('ph_type_id');

        $this->answerPh(['is_ph' => '1', 'ph_previously_availed' => '1', 'ph_type_id' => (string) PhType::query()->where('code', 'OAL')->value('id')]);
        $this->assertSame('OAL', $this->row->refresh()->phType?->code);
        $this->get($this->route('show'))->assertOk()->assertSee('OAL — One Arm and One Leg');

        // Not PH: no type is kept.
        $this->answerPh(['is_ph' => '0']);
        $this->assertNull($this->row->refresh()->ph_type_id);
    }

    /**
     * A Property Return the promotion rule makes mandatory, not submitted, is Missing
     * (2026-11-05): its reason and document are required on the form; an optional
     * year asks for neither; the AH reads the evidence; the Rule Check says CIR.
     */
    public function test_a_missing_mandatory_property_return_needs_its_reason_and_document(): void
    {
        $this->promotionRule(propertyYears: 1); // 2025 mandatory, 2024 optional

        $this->actingAs($this->ao)->post($this->route('property.save'), ['year' => 2025, 'submitted' => '0'])
            ->assertSessionHasErrors(['missing_reason_id', 'missing_document']);
        $this->assertFalse($this->row->propertyFlags()->where('year', 2025)->exists());

        $this->post($this->route('property.save'), ['year' => 2024, 'submitted' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->row->recordGaps()->count(), 'An optional year asks for no reason or document.');

        $this->post($this->route('property.save'), [
            'year' => 2025, 'submitted' => '0',
            'missing_reason_id' => RecordReason::query()->where('code', 'record_not_available')->value('id'),
            'missing_document' => $this->pdf('ipr-missing.pdf'),
        ])->assertSessionHasNoErrors();
        $gap = $this->row->recordGaps()->where('kind', 'property')->where('year', 2025)->sole();
        $this->assertTrue($gap->isJustified());
        $this->assertSame([], array_filter($this->details()['sections']['property'], fn (string $m): bool => str_contains($m, '2025')));
        $this->assertContains(__('dpc.employee_details.rule.property_cir', ['year' => 2025]), $this->ruleCheck()['holds']);

        // The AH reviews the evidence, and cannot change it.
        $this->actingAs($this->ah)->get(route('dpc-proposals.record-gaps.index', [$this->proposal, $this->row]))->assertOk()
            ->assertSee(__('dpc.record_gaps.kind_property'))->assertSee('2025')
            ->assertDontSee('data-record-gap-form', false);
        $this->actingAs($this->ah)->get(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row, $gap]))->assertOk();
    }
}
