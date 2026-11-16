<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Services\CandidateGateService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\RecordReason;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Employee details, business decisions of 2026-11-14: Integrity beside each CR year's
 * grade (Fit/Unfit from the Administrator's master), "appointed in this year" as an
 * Unfit reason with no document, the Department Enquiry/Court Case questions, the CR
 * and Property Return year windows, the IPR due date, and "Save & Next".
 */
class EmployeeDetailsUnfitTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    private int $dpcYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk'));

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();

        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);

        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Under Secretary'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());

        $this->row = $this->proposal->evaluationRows()->sole();
        $this->dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
    }

    private function route(string $name, array $extra = []): string
    {
        return route("dpc-proposals.employees.{$name}", [$this->proposal, $this->row, ...$extra]);
    }

    private function pdf(string $name = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    private function integrity(string $code): int
    {
        return (int) IntegrityOption::query()->where('code', $code)->value('id');
    }

    private function saveCr(int $year, array $extra = [])
    {
        return $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क',
            'integrity_option_id' => $this->integrity('certified_good'), ...$extra,
        ]);
    }

    private function unfit(): array
    {
        return $this->row->fresh()->unfitReasons();
    }

    // ================================================================ Integrity

    public function test_the_four_integrity_options_are_seeded_with_their_result(): void
    {
        $this->assertSame(
            ['certified_good' => true, 'not_certified_adverse' => false, 'not_mentioned_good' => true, 'not_mentioned_adverse' => false],
            IntegrityOption::query()->selectable()->pluck('fit', 'code')->all(),
        );
    }

    public function test_integrity_is_chosen_beside_the_grade_and_an_unfit_answer_makes_the_officer_unfit(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()
            ->assertSee('name="integrity_option_id"', false)
            ->assertSee('Not Certified + Adverse Remarks and/or Bad Grade');

        // Recorded without it, the year asks for it.
        $this->saveCr($this->dpcYear - 1, ['integrity_option_id' => ''])->assertSessionHasNoErrors();
        $this->assertContains(__('dpc.employee_details.missing.cr_integrity', ['year' => $this->dpcYear - 1]), app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr']);

        $this->saveCr($this->dpcYear - 1)->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit());
        $this->assertTrue((bool) $this->row->loyaltyFlags()->where('year', $this->dpcYear - 1)->value('flag'), 'The engine reads the Integrity Certificate as held.');

        $this->saveCr($this->dpcYear - 2, ['integrity_option_id' => $this->integrity('not_mentioned_adverse')])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->unfit());

        $check = app(PromotionRuleCheck::class)->check($this->row->fresh(), $this->proposal);
        $this->assertSame('does_not_meet', $check['status'], 'Shown Unfit.');

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('data-cr-integrity="'.($this->dpcYear - 2).'"', false)
            ->assertSee('data-rule-status="does_not_meet"', false);

        // The engine refuses the candidate on it.
        $verdict = app(CandidateGateService::class)->assess(OfficerRecord::fromEvaluationRow($this->row->fresh()), [], 0, 0, RemarkCode::NotEligible);
        $this->assertSame(RemarkCode::NotEligible, $verdict->status);
        $this->assertStringContainsString('Integrity for '.($this->dpcYear - 2), implode(' ', $verdict->reasons));
    }

    public function test_the_administrator_manages_the_integrity_options(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $this->actingAs($admin)->get(route('admin.integrity-options.index'))->assertOk()
            ->assertSee('Certified + No Adverse Remarks and/or Good Grade')
            ->assertSee('data-integrity-fit="unfit"', false);

        $this->post(route('admin.integrity-options.store'), [
            'code' => 'withheld', 'name_en' => 'Withheld', 'name_hi' => 'रोका गया', 'sort_order' => 50, 'status' => 'active',
        ])->assertRedirect(route('admin.integrity-options.index'));
        $this->assertFalse(IntegrityOption::query()->where('code', 'withheld')->sole()->fit, 'Unticked: Unfit.');

        $this->actingAs($this->ao)->get(route('admin.integrity-options.index'))->assertForbidden();
    }

    // =================================================== Appointed in this year

    public function test_appointed_in_this_year_needs_no_document_and_makes_the_officer_unfit(): void
    {
        $reason = RecordReason::query()->where('code', 'newly_appointed')->sole();
        $this->assertTrue($reason->makes_unfit);
        $this->assertSame('Appointed in this year', $reason->name_en);

        $html = (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->getContent();
        $this->assertMatchesRegularExpression('/value="'.$reason->id.'"\s+data-makes-unfit/', $html, 'The form knows the reason asks for no document.');

        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 1, 'put_up' => '0', 'missing_reason_id' => $reason->id,
        ])->assertSessionHasNoErrors();

        $gap = DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->sole();
        $this->assertNull($gap->path, 'No document is asked.');
        $this->assertTrue($gap->isJustified());
        $this->assertNotContains(__('dpc.employee_details.missing.cr_reason', ['year' => $this->dpcYear - 1]), app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr']);
        $this->assertCount(1, $this->unfit());

        // The reason is shown in the CR table.
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('data-cr-missing-reason="'.($this->dpcYear - 1).'"', false);

        // Any other reason still needs its document.
        $this->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 2, 'put_up' => '0', 'missing_reason_id' => RecordReason::query()->where('code', 'record_lost')->value('id'),
        ])->assertSessionHasErrors('missing_document');
    }

    // ========================================== Department Enquiry / Court Case

    public function test_both_enquiry_questions_are_answered_with_a_document_and_a_yes_is_unfit(): void
    {
        $page = $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()
            ->assertSee(__('dpc.employee_details.sections.vigilance'))
            ->assertSee('Department Enquiry/Court Case (Sealed Cover)');
        $page->assertSee(__('dpc.employee_details.vigilance.enquiry_question', [
            'from' => '01-01-'.($this->dpcYear - 1), 'to' => $this->proposal->proposal_date->format('d-m-Y'),
        ]));

        $form = ['enquiry_ongoing' => '0', 'punishment_effective' => '1', 'vigilance_pending' => '0', 'vigilance_verified' => '1'];

        // The document is mandatory for Yes and for No.
        $this->put($this->route('vigilance'), $form)->assertSessionHasErrors(['enquiry_document', 'punishment_document']);
        $this->put($this->route('vigilance'), ['vigilance_pending' => '0'])->assertSessionHasErrors(['enquiry_ongoing', 'punishment_effective']);
        // Verified against its original (rule 4.4).
        $this->put($this->route('vigilance'), [...$form, 'vigilance_verified' => '0', 'enquiry_document' => $this->pdf(), 'punishment_document' => $this->pdf()])
            ->assertSessionHasErrors('vigilance_verified');

        $this->put($this->route('vigilance'), [...$form, 'enquiry_document' => $this->pdf('e.pdf'), 'punishment_document' => $this->pdf('p.pdf')])
            ->assertSessionHasNoErrors();

        $row = $this->row->fresh();
        $this->assertFalse($row->enquiry_ongoing);
        $this->assertTrue($row->punishment_effective);
        $this->assertSame(2, $row->currentDocuments()->whereIn('kind', [EvaluationDocumentKind::EnquiryDocument->value, EvaluationDocumentKind::PunishmentDocument->value])->count());
        $this->assertSame([__('dpc.employee_details.unfit.punishment_effective')], $this->unfit());
        $this->assertSame([], app(EmployeeDetailsService::class)->status($row)['sections']['vigilance']);

        // Saved again, the documents on file stand.
        $this->put($this->route('vigilance'), [...$form, 'punishment_effective' => '0'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit());
    }

    // ============================================================ The windows

    public function test_the_cr_window_is_five_years_stretched_by_a_middle_gap_but_not_by_the_last_year(): void
    {
        $service = app(EmployeeDetailsService::class);
        $last = $this->dpcYear - 1;
        $this->assertSame(range($last, $last - 4), $service->crYearWindow($this->row->fresh()));

        // The last year missing: still the same five years — four CRs suffice.
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $last, 'put_up' => '0', 'missing_reason_id' => RecordReason::query()->where('code', 'long_leave')->value('id'), 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(range($last, $last - 4), $service->crYearWindow($this->row->fresh()));

        // A year in the middle missing, or without grading: one more year back for each.
        $this->post($this->route('cr.save'), [
            'year' => $last - 2, 'put_up' => '0', 'missing_reason_id' => RecordReason::query()->where('code', 'long_leave')->value('id'), 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(range($last, $last - 5), $service->crYearWindow($this->row->fresh()));
        $this->post($this->route('cr.save'), ['year' => $last - 3, 'put_up' => '1', 'has_grading' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(range($last, $last - 6), $service->crYearWindow($this->row->fresh()));

        // A year outside the window is refused.
        $this->saveCr($last - 7)->assertSessionHasErrors('cr');
        $this->saveCr($last - 6)->assertSessionHasNoErrors();
    }

    public function test_the_property_return_is_exactly_the_five_years_before_the_dpc_year(): void
    {
        $service = app(EmployeeDetailsService::class);
        $this->assertSame(range($this->dpcYear - 1, $this->dpcYear - 5), $service->propertyYearWindow($this->row));

        $missing = $service->status($this->row->fresh())['sections']['property'];
        foreach (range($this->dpcYear - 5, $this->dpcYear - 1) as $year) {
            $this->assertContains(__('dpc.employee_details.missing.property_window_year', ['year' => $year]), $missing);
        }

        $this->actingAs($this->ao)->post($this->route('property.save'), [
            'year' => $this->dpcYear - 6, 'submitted' => '1', 'submitted_on' => ($this->dpcYear - 6).'-01-10', 'next_increment_date' => ($this->dpcYear - 6).'-07-01',
        ])->assertSessionHasErrors('property');
        $this->assertSame(0, $this->row->propertyFlags()->count());
    }

    /** 2026-11-14: the 2025 return is due by 31 January 2025, not 2026. */
    public function test_a_return_is_late_after_31_january_of_its_own_year(): void
    {
        $year = $this->dpcYear - 2;
        $form = ['year' => $year, 'submitted' => '1', 'next_increment_date' => $year.'-07-01'];

        $this->actingAs($this->ao)->post($this->route('property.save'), [...$form, 'submitted_on' => $year.'-01-31'])->assertSessionHasNoErrors();
        $this->assertFalse($this->row->propertyFlags()->sole()->isLate());

        $this->post($this->route('property.save'), [...$form, 'submitted_on' => $year.'-02-01'])->assertSessionHasErrors('late_decision');

        // The decision's document goes with the year.
        $this->post($this->route('property.save'), [
            ...$form, 'submitted_on' => $year.'-02-01', 'late_decision' => 'accepted', 'late_decision_remarks' => 'Election duty.',
            'late_document' => $this->pdf('decision.pdf'), 'property_verified' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::PropertyLateDecision->value)->where('year', $year)->count());
    }

    // ============================================================ Save & Next

    public function test_save_and_next_lands_on_the_next_section(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('name="_next" value="cr"', false)
            ->assertSee('js/forms/dpc-employee-ajax.js', false)
            ->assertSee('data-ajax-section', false);

        $this->put($this->route('basic'), [
            'dob_order_number' => 'B/1', 'dob_order_date' => '1970-03-25', 'joining_order_number' => 'J/1', 'joining_order_date' => '1995-07-01',
            'service_record_undertaking' => '1', '_next' => 'cr',
        ])->assertRedirect(route('dpc-proposals.employees.show', [$this->proposal, $this->row]).'#section-cr');

        // Anything but a section is ignored.
        $this->put($this->route('basic'), ['service_record_undertaking' => '1', '_next' => 'https://example.com'])
            ->assertRedirect(route('dpc-proposals.employees.show', [$this->proposal, $this->row]).'#section-basic');
    }
}
