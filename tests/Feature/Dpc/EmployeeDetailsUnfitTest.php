<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\CandidateGateService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The years the CR form's own year list offers (not any other list on the page).
     *
     * @return list<int>
     */
    private function crOptions(): array
    {
        $html = (string) $this->actingAs($this->ao)->get($this->route('show'))->assertOk()->getContent();

        if (! preg_match('/<select[^>]*id="cr-year"[^>]*>(.*?)<\/select>/s', $html, $select)) {
            return [];
        }

        preg_match_all('/<option value="(\d{4})"/', $select[1], $years);

        return array_map('intval', $years[1]);
    }

    private function unfit(): array
    {
        return $this->row->fresh()->unfitReasons();
    }

    // ================================================================ Integrity

    public function test_the_five_integrity_options_are_seeded_with_their_result(): void
    {
        $this->assertSame(
            ['certified_good' => true, 'not_certified_adverse' => false, 'not_certified_good' => false, 'not_mentioned_good' => true, 'not_mentioned_adverse' => false],
            IntegrityOption::query()->selectable()->pluck('fit', 'code')->all(),
        );
        // 2026-11-16: these three are decided by the Final Departmental View.
        $this->assertSame(
            ['not_certified_good', 'not_mentioned_good', 'not_mentioned_adverse'],
            IntegrityOption::query()->selectable()->where('needs_departmental_view', true)->pluck('code')->all(),
        );
    }

    /** 2026-11-16: the Final Departmental View Regarding Integrity, with its document, decides. */
    public function test_the_final_departmental_view_decides_fit_or_unfit(): void
    {
        $year = $this->dpcYear - 1;
        $option = ['integrity_option_id' => $this->integrity('not_mentioned_good')];

        $page = $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee(__('dpc.employee_details.cr.integrity_view'))
            ->assertSee(__('dpc.employee_details.cr.integrity_document'));
        $this->assertMatchesRegularExpression('/value="'.$this->integrity('not_mentioned_good').'"\s+data-needs-view/', (string) $page->getContent());

        // The view and its document are mandatory.
        $this->saveCr($year, $option)->assertSessionHasErrors(['integrity_departmental_view', 'integrity_document']);
        $this->assertSame(0, $this->row->crGrades()->count());

        // Integrity Not Certified: Unfit, whatever the answer.
        $this->saveCr($year, $option + ['integrity_departmental_view' => 'not_certified', 'integrity_document' => $this->pdf('view.pdf'), 'cr_verified' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('not_certified', $this->row->crGrades()->sole()->integrity_departmental_view);
        $this->assertSame(1, $this->row->currentDocuments()->where('kind', 'integrity_decision')->where('year', $year)->count());
        $this->assertCount(1, $this->unfit());
        $this->assertStringContainsString('Integrity Not Certified', $this->row->fresh()->unfitReasons('en')[0]);

        // Integrity Certified: Fit — the document already on file is not asked again.
        $this->saveCr($year, $option + ['integrity_departmental_view' => 'certified'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit());
        $this->assertNotContains(__('dpc.employee_details.missing.cr_integrity_document', ['year' => $year]), app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr']);

        // Uploaded on its own, the Integrity decision belongs to the CR section.
        $this->post($this->route('documents.store'), ['verified' => '1', 'kind' => 'integrity_decision', 'year' => $year, 'document' => $this->pdf('view2.pdf')])
            ->assertRedirect(route('dpc-proposals.employees.show', [$this->proposal, $this->row]).'#section-cr');
        $this->assertSame(1, $this->row->currentDocuments()->where('kind', 'integrity_decision')->where('year', $year)->count(), 'The new document replaces the old.');

        // An answer that settles it by itself keeps no view.
        $this->saveCr($year, ['integrity_departmental_view' => 'not_certified'])->assertSessionHasNoErrors();
        $this->assertNull($this->row->crGrades()->sole()->integrity_departmental_view);
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

        $this->saveCr($this->dpcYear - 2, ['integrity_option_id' => $this->integrity('not_certified_adverse')])->assertSessionHasNoErrors();
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

    /** 2026-11-16: the Administrator keeps the CR's reasons and the Property Return's apart. */
    public function test_the_cr_and_property_return_reasons_are_kept_apart_by_the_administrator(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        $reason = RecordReason::query()->where('code', 'newly_appointed')->sole();
        $form = ['code' => $reason->code, 'name_en' => $reason->name_en, 'name_hi' => $reason->name_hi, 'sort_order' => $reason->sort_order, 'status' => 'active', 'makes_unfit' => '1'];

        // At least one of the two.
        $this->actingAs($admin)->put(route('admin.record-reasons.update', $reason), $form)->assertSessionHasErrors('for_property');
        $this->put(route('admin.record-reasons.update', $reason), $form + ['for_cr' => '1'])->assertRedirect(route('admin.record-reasons.index'));
        $this->assertTrue($reason->fresh()->for_cr);
        $this->assertFalse($reason->fresh()->for_property);
        $this->get(route('admin.record-reasons.index'))->assertSee('data-reason-applies="newly_appointed"', false);

        // Offered under the CR only.
        $html = (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->getContent();
        $cr = substr($html, (int) strpos($html, 'id="cr-missing-reason"'), 4000);
        $property = substr($html, (int) strpos($html, 'id="property-missing-reason"'), 4000);
        $this->assertStringContainsString('value="'.$reason->id.'"', substr($cr, 0, (int) strpos($cr, '</select>')));
        $this->assertStringNotContainsString('value="'.$reason->id.'"', substr($property, 0, (int) strpos($property, '</select>')));

        // A reason offered for the Property Return only is refused for a CR.
        $this->actingAs($admin)->put(route('admin.record-reasons.update', $reason), $form + ['for_property' => '1'])->assertRedirect();
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 1, 'put_up' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
        ])->assertSessionHasErrors(['missing_reason_id' => __('dpc.employee_details.missing_record.reason_not_for_kind')]);
        $this->assertSame(0, DpcEvaluationRecordGap::query()->count());
    }

    // =================================================== Appointed in this year

    /**
     * 2026-11-18, the year mapping: a middle CR missing takes the next older year,
     * and the Integrity follows the CR to that year; the IPR keeps the DPC year's
     * own window and does not move back.
     */
    public function test_integrity_follows_a_substituted_cr_year_and_the_ipr_does_not(): void
    {
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->forceFill(['cr_years' => 5])->save();
        $rule->years()->update(['loyalty_mandatory' => true, 'property_mandatory' => true]);
        $last = $this->dpcYear - 1;                     // 2025 for a 2026 DPC
        $missing = $last - 2;                           // 2023
        $reason = RecordReason::query()->where('code', 'record_not_available')->value('id');

        foreach ([$last, $last - 1, $last - 3, $last - 4] as $year) {
            $this->saveCr($year)->assertSessionHasNoErrors();
        }
        $this->post($this->route('cr.save'), ['year' => $missing, 'put_up' => '0', 'missing_reason_id' => $reason, 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        // The older year joins the CR window in 2023's place, and is recorded with its Integrity.
        $this->assertContains($last - 5, app(EmployeeDetailsService::class)->crYearWindow($this->row->fresh()));
        $this->saveCr($last - 5)->assertSessionHasNoErrors();

        $row = $this->row->fresh();
        $counted = [$last, $last - 1, $last - 3, $last - 4, $last - 5];
        $this->assertSame(range($last, $last - 4), app(EmployeeDetailsService::class)->propertyYearWindow($row), 'The IPR does not move back.');

        $check = collect(app(PromotionRuleCheck::class)->check($row, $this->proposal->fresh())['years'])->keyBy('year');
        foreach ($counted as $year) {
            $this->assertTrue($check[$year]['loyalty_required'], "Integrity of the counted CR year {$year}.");
        }
        $this->assertFalse($check[$missing]['loyalty_required'], 'No Integrity for the substituted-away year.');
        $this->assertTrue($check[$missing]['property_required'], 'The IPR of 2023 is still asked.');
        $this->assertFalse($check[$last - 5]['property_required'], 'No IPR for the substituted-in year.');
    }

    /** @return array<string, array{int}> */
    public static function crYearCounts(): array
    {
        return ['3 years' => [3], '4 years' => [4], '5 years' => [5], '7 years' => [7]];
    }

    /**
     * 2026-11-18: as many years of IPR and Integrity as of CR — on the screen, in the
     * rule check and in the calculation's rule — even where the rule fixed on the DPC
     * was recorded with another IPR figure (0 here, the PPT rule's).
     */
    #[DataProvider('crYearCounts')]
    public function test_the_ipr_and_integrity_years_follow_the_cr_years(int $years): void
    {
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->forceFill(['cr_years' => $years, 'property_years' => 0, 'loyalty_years' => 0])->save();
        $last = $this->dpcYear - 1;
        $window = range($last, $last - $years + 1);
        $details = app(EmployeeDetailsService::class);
        $row = $this->row->fresh();

        $this->assertSame($window, $details->crYearWindow($row), 'CR years.');
        $this->assertSame($window, $details->propertyYearWindow($row), 'IPR years: the same.');
        $this->assertSame([$years, $years], [$rule->fresh()->property_years, $rule->fresh()->loyalty_years]);

        // A year beyond them is refused for either; the oldest of them is taken.
        $this->saveCr($last - $years)->assertSessionHasErrors();
        $this->saveCr($last - $years + 1)->assertSessionHasNoErrors();
        $this->post($this->route('property.save'), ['year' => $last - $years, 'submitted' => '0'])->assertSessionHasErrors();

        // The rule check asks IPR and Integrity of no year outside them.
        $check = app(PromotionRuleCheck::class)->check($this->row->fresh(), $this->proposal->fresh());
        foreach ($check['years'] as $entry) {
            if (! in_array($entry['year'], $window, true)) {
                $this->assertFalse($entry['property_required'], 'No IPR is required for '.$entry['year']);
                $this->assertFalse($entry['loyalty_required'], 'No Integrity is required for '.$entry['year']);
            }
        }

        // The calculation's rule reads the same years.
        $set = RuleSet::forPromotionRule(RuleSet::fromConfig(), $rule->fresh()->load('years'), [], $this->dpcYear, 'I-I');
        $this->assertSame([], array_diff((array) $set->propertyMandatoryYears(), $window));
    }

    /**
     * 2026-11-18: a promotion rule may say "appointed in this year" ends the years
     * asked for — none older — and does not make the officer Unfit. The CRs left are
     * judged against the full qualifying marks (the Commission's interim decision).
     */
    public function test_under_its_rule_appointed_in_this_year_asks_no_older_year_and_is_not_unfit(): void
    {
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->forceFill(['cr_years' => 3, 'appointed_year_is_fit' => true, 'qualifying_total' => 14])->save();
        $last = $this->dpcYear - 1;
        $reason = RecordReason::query()->where('code', 'newly_appointed')->sole();

        $this->saveCr($last)->assertSessionHasNoErrors();
        $this->saveCr($last - 1)->assertSessionHasNoErrors();
        $this->post($this->route('cr.save'), [
            'year' => $last - 2, 'put_up' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $row = $this->row->fresh();

        $this->assertSame([], $this->unfit(), 'Not Unfit under this rule.');
        $this->assertSame([$last, $last - 1, $last - 2], app(EmployeeDetailsService::class)->crYearWindow($row), 'No year before the appointment is asked for.');
        $this->assertSame([$last, $last - 1, $last - 2], app(EmployeeDetailsService::class)->propertyYearWindow($row));
        $this->assertSame($last - 2, OfficerRecord::fromEvaluationRow($row)->appointedYear);

        // The two CRs (क 7 + क 7 = 14) and the third year filled with their average
        // (7, 2026-11-19): 21 against the full qualifying marks of 14 — met.
        $check = app(PromotionRuleCheck::class)->check($row, $this->proposal->fresh());
        $this->assertSame([], array_filter($check['findings'], fn (string $f): bool => str_contains($f, (string) ($last - 3))), 'Nothing older is a finding.');
        $this->assertSame(21, $check['total']);

        // Full marks, not reduced: 22 is not met.
        $rule->forceFill(['qualifying_total' => 22])->save();
        $check = app(PromotionRuleCheck::class)->check($row->fresh(), $this->proposal->fresh());
        $this->assertContains(__('dpc.employee_details.rule.total_below', ['total' => 21, 'required' => 22]), $check['findings']);

        // Without the rule's Yes, the same record makes the officer Unfit, as before.
        $rule->forceFill(['appointed_year_is_fit' => false])->save();
        $this->assertCount(1, $this->row->fresh()->unfitReasons());
    }

    /**
     * 2026-11-19: whatever the rule says, nothing before the year recorded "appointed in
     * this year" is asked for — no CR or Property File exists before it. The rule decides
     * only Fit or Unfit: without its Yes the officer is still Unfit.
     */
    public function test_without_the_rules_yes_appointed_in_this_year_still_asks_no_older_year(): void
    {
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->forceFill(['cr_years' => 5, 'appointed_year_is_fit' => false])->save();
        $last = $this->dpcYear - 1;
        $reason = RecordReason::query()->where('code', 'newly_appointed')->sole();

        $this->saveCr($last)->assertSessionHasNoErrors();
        $this->post($this->route('cr.save'), [
            'year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $row = $this->row->fresh();

        $this->assertSame([$last, $last - 1], app(EmployeeDetailsService::class)->crYearWindow($row), 'No CR year before the appointment.');
        $this->assertSame([$last, $last - 1], app(EmployeeDetailsService::class)->propertyYearWindow($row), 'No Property File year before it either.');
        $this->assertNotContains(__('dpc.employee_details.missing.cr_reason', ['year' => $last - 2]), app(EmployeeDetailsService::class)->status($row)['sections']['cr']);

        // A CR before the appointment is refused.
        $this->saveCr($last - 2)->assertSessionHasErrors();
        $this->assertFalse($row->crGrades()->where('year', $last - 2)->exists());

        // Still Unfit: the rule did not say Yes.
        $this->assertNotEmpty($this->unfit());
    }

    /**
     * 2026-11-19: a CR recorded missing without remarks never ends in a server error —
     * whatever the reason, first time or again on the same year: either it is saved, or
     * the form says what is missing.
     */
    public function test_a_missing_cr_without_remarks_is_refused_on_the_form_never_a_server_error(): void
    {
        $year = $this->dpcYear - 2;

        foreach (RecordReason::query()->get() as $reason) {
            if (! $reason->appliesTo(DpcEvaluationRecordGap::KIND_CR)) {
                continue;
            }

            $payload = ['year' => $year, 'put_up' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
                ...($reason->requiresSecondDocument() ? ['missing_second_document' => $this->pdf('second.pdf')] : [])];

            $first = $this->actingAs($this->ao)->post($this->route('cr.save'), $payload);
            $this->assertSame(302, $first->getStatusCode(), $reason->code.' (first time)');
            if ($reason->requires_remarks) {
                $first->assertSessionHasErrors('missing_remarks');
            }

            // Saved once with its remarks, then saved again without: still no server error.
            $this->actingAs($this->ao)->post($this->route('cr.save'), [...$payload, 'missing_document' => $this->pdf(), 'missing_remarks' => 'Recorded.']);
            $again = $this->actingAs($this->ao)->post($this->route('cr.save'), [...$payload, 'missing_document' => $this->pdf()]);
            $this->assertSame(302, $again->getStatusCode(), $reason->code.' (again, without remarks)');
            DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->delete();
        }
    }

    /** The same for a Property File recorded missing (2026-11-19). */
    public function test_a_missing_property_file_without_remarks_is_refused_on_the_form_never_a_server_error(): void
    {
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->years()->update(['property_mandatory' => true]);
        $year = $this->dpcYear - 2;
        $this->assertContains($year, app(EmployeeDetailsService::class)->rulePropertyYears($this->row->fresh()));
        $tried = 0;

        foreach (RecordReason::query()->get() as $reason) {
            if (! $reason->appliesTo(DpcEvaluationRecordGap::KIND_PROPERTY)) {
                continue;
            }

            $tried++;
            $payload = ['year' => $year, 'submitted' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
                ...($reason->requiresSecondDocument() ? ['missing_second_document' => $this->pdf('second.pdf')] : [])];

            $first = $this->actingAs($this->ao)->post($this->route('property.save'), $payload);
            $this->assertSame(302, $first->getStatusCode(), $reason->code.' (first time)');
            if ($reason->requires_remarks) {
                $first->assertSessionHasErrors('missing_remarks');
            }

            $this->actingAs($this->ao)->post($this->route('property.save'), [...$payload, 'missing_document' => $this->pdf(), 'missing_remarks' => 'Recorded.']);
            $again = $this->actingAs($this->ao)->post($this->route('property.save'), [...$payload, 'missing_document' => $this->pdf()]);
            $this->assertSame(302, $again->getStatusCode(), $reason->code.' (again, without remarks)');
            DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->delete();
        }

        $this->assertGreaterThan(0, $tried, 'Some reason applies to a Property File.');
    }

    /**
     * 2026-11-19: "Appointed in this year" chosen by mistake and the CR year then
     * removed — the reason goes with it (on the timeline), so nothing is limited by it
     * any more and the older years are asked for again; the year comes back to the list.
     */
    public function test_removing_a_year_recorded_appointed_by_mistake_lifts_the_limit(): void
    {
        $service = app(EmployeeDetailsService::class);
        $last = $this->dpcYear - 1;
        $appointed = RecordReason::query()->where('code', 'newly_appointed')->value('id');

        $this->saveCr($last)->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $appointed, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame([$last, $last - 1], $service->crYearWindow($this->row->fresh()), 'Limited by the appointment.');
        $this->saveCr($last - 2)->assertSessionHasErrors();

        // Removed: the reason is withdrawn, the trail kept.
        $this->delete($this->route('cr.remove', [$last - 1]))->assertSessionHasNoErrors();
        $row = $this->row->fresh();
        $this->assertNull($row->appointedYear());
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $row->id)->count());
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'dpc_proposal.record_gap_withdrawn')->count());

        // The five years again, the removed year offered again, and an older year saves.
        $this->assertSame(range($last, $last - 4), $service->crYearWindow($row));
        $this->assertSame([$last - 1, $last - 2, $last - 3, $last - 4], $this->crOptions());
        $this->saveCr($last - 2)->assertSessionHasNoErrors();
        $this->assertEmpty($this->unfit(), 'No longer Unfit for the appointment.');
    }

    /** The same mistake corrected with Edit: another reason, or the CR found (2026-11-19). */
    public function test_editing_a_year_recorded_appointed_by_mistake_lifts_the_limit(): void
    {
        $service = app(EmployeeDetailsService::class);
        $last = $this->dpcYear - 1;
        $appointed = RecordReason::query()->where('code', 'newly_appointed')->value('id');
        $other = RecordReason::query()->where('code', 'long_leave')->value('id');
        $this->saveCr($last)->assertSessionHasNoErrors();
        $markAppointed = fn () => $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $appointed, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        // Edited to another reason: still missing, replaced by an older year, no limit.
        $markAppointed();
        $this->assertSame([$last, $last - 1], $service->crYearWindow($this->row->fresh()));
        $this->post($this->route('cr.save'), ['year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $other, 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear());
        $this->assertSame(range($last, $last - 5), $service->crYearWindow($this->row->fresh()));

        // Edited to "put up" (the CR was found): its reason is withdrawn.
        $markAppointed();
        $this->saveCr($last - 1)->assertSessionHasNoErrors();
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->where('year', $last - 1)->count());
        $this->assertNull($this->row->fresh()->appointedYear());
        $this->assertSame(range($last, $last - 4), $service->crYearWindow($this->row->fresh()));
    }

    /**
     * Years keep being offered until five are counted, or the year of appointment is
     * reached — then no more (2026-11-19).
     */
    public function test_years_are_offered_until_five_are_counted_or_the_appointment_is_reached(): void
    {
        $last = $this->dpcYear - 1;
        $reason = RecordReason::query()->where('code', 'long_leave')->value('id');

        // Five counted with one missing in between: six years asked, then nothing more.
        $this->saveCr($last)->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $reason, 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        foreach ([2, 3, 4, 5] as $back) {
            $this->saveCr($last - $back)->assertSessionHasNoErrors();
        }
        $this->assertSame([], $this->crOptions());
        $this->actingAs($this->ao)->get($this->route('show'))->assertSee('id="cr-years-complete"', false);
        $this->saveCr($last - 6)->assertSessionHasErrors();

        // Appointed three years back: missing before it is not replaced — nothing older is offered.
        DpcEvaluationCrGrade::query()->where('dpc_evaluation_row_id', $this->row->id)->where('year', '<=', $last - 2)->delete();
        $this->actingAs($this->ao)->post($this->route('cr.save'), ['year' => $last - 3, 'put_up' => '0', 'missing_reason_id' => RecordReason::query()->where('code', 'newly_appointed')->value('id'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertSame([$last - 2], $this->crOptions());
    }

    /** CRs missing year after year: one older year for each, never stopping at five (2026-11-19). */
    public function test_cr_missing_year_after_year_keeps_reaching_back(): void
    {
        $service = app(EmployeeDetailsService::class);
        $last = $this->dpcYear - 1;
        $reason = RecordReason::query()->where('code', 'long_leave')->value('id');
        $this->saveCr($last)->assertSessionHasNoErrors();

        for ($year = $last - 1; $year >= $last - 8; $year--) {
            // Each year is offered on the screen before it is recorded missing.
            $this->assertContains($year, $this->crOptions());
            $this->actingAs($this->ao)->post($this->route('cr.save'), [
                'year' => $year, 'put_up' => '0', 'missing_reason_id' => $reason, 'missing_document' => $this->pdf(),
            ])->assertSessionHasNoErrors();
        }

        // Eight missing after the last year: 1 + 8 + four still to count.
        $this->assertSame(range($last, $last - 12), $service->crYearWindow($this->row->fresh()));
        $this->assertSame(range($last - 9, $last - 12), $this->crOptions());
    }

    /** A year removed — graded, or a Property File — is offered again (2026-11-19). */
    public function test_a_removed_year_is_offered_again(): void
    {
        $last = $this->dpcYear - 1;
        $this->saveCr($last - 1)->assertSessionHasNoErrors();
        $this->assertNotContains($last - 1, $this->crOptions());

        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$last - 1]))->assertSessionHasNoErrors();
        $this->assertContains($last - 1, $this->crOptions());

        // A Property File recorded missing and removed: its reason goes too.
        $rule = app(PromotionCrRules::class)->ruleForProposal($this->proposal);
        $rule->years()->update(['property_mandatory' => true]);
        $this->post($this->route('property.save'), [
            'year' => $last - 1, 'submitted' => '0', 'missing_reason_id' => RecordReason::query()->get()->first(fn ($r) => $r->appliesTo('property') && ! $r->requires_remarks && ! $r->requiresSecondDocument())?->id,
            'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DpcEvaluationRecordGap::query()->where('kind', DpcEvaluationRecordGap::KIND_PROPERTY)->count());
        $this->delete($this->route('property.remove', [$last - 1]))->assertSessionHasNoErrors();
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('kind', DpcEvaluationRecordGap::KIND_PROPERTY)->count());
    }

    public function test_appointed_in_this_year_asks_for_the_appointment_letter_and_makes_the_officer_unfit(): void
    {
        $reason = RecordReason::query()->where('code', 'newly_appointed')->sole();
        $this->assertTrue($reason->makes_unfit);
        $this->assertSame('Appointed in this year', $reason->name_en);
        $this->assertSame('Appointment letter', $reason->document_label_en);

        $html = (string) $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->getContent();
        $this->assertMatchesRegularExpression('/value="'.$reason->id.'"\s+data-makes-unfit\s+data-document-label="Appointment letter"/', $html, 'The form names the document it asks for.');

        // The appointment letter is mandatory (2026-11-16).
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 1, 'put_up' => '0', 'missing_reason_id' => $reason->id,
        ])->assertSessionHasErrors('missing_document');
        $this->assertSame(0, DpcEvaluationRecordGap::query()->count());

        $this->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 1, 'put_up' => '0', 'missing_reason_id' => $reason->id, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $gap = DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->sole();
        $this->assertNotNull($gap->path, 'The appointment letter is kept.');
        $this->assertTrue($gap->isJustified());
        $this->assertNotContains(__('dpc.employee_details.missing.cr_reason', ['year' => $this->dpcYear - 1]), app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr']);
        $this->assertCount(1, $this->unfit());

        // The reason is shown in the CR table.
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('data-cr-missing-reason="'.($this->dpcYear - 1).'"', false)
            // …and the appointment letter in the Documents column (2026-11-16).
            ->assertSee('data-cr-gap-document="'.($this->dpcYear - 1).'"', false)
            ->assertSee(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row, $gap]), false);

        // Any other reason still needs its document.
        $this->post($this->route('cr.save'), [
            'year' => $this->dpcYear - 2, 'put_up' => '0', 'missing_reason_id' => RecordReason::query()->where('code', 'record_lost')->value('id'),
        ])->assertSessionHasErrors('missing_document');
    }

    // ========================================== Department Enquiry / Court Case

    public function test_a_yes_to_an_enquiry_question_needs_its_document_and_is_unfit_and_a_no_needs_none(): void
    {
        $page = $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertOk()
            ->assertSee(__('dpc.employee_details.sections.vigilance'))
            ->assertSee('Department Enquiry/Court Case (Sealed Cover)');
        $page->assertSee(__('dpc.employee_details.vigilance.enquiry_question', [
            'from' => '01-01-'.$this->dpcYear, 'to' => $this->proposal->proposal_date->format('d-m-Y'),
        ]));

        $form = ['enquiry_ongoing' => '0', 'punishment_effective' => '1', 'vigilance_pending' => '0', 'vigilance_verified' => '1'];

        // The document is mandatory for Yes only (2026-11-16).
        $this->put($this->route('vigilance'), $form)->assertSessionHasErrors('punishment_document')->assertSessionDoesntHaveErrors('enquiry_document');
        $page = $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]));
        $page->assertSee('data-show-when="enquiry_ongoing=1" data-vigilance-document="enquiry_ongoing"', false)
            ->assertSee('data-show-when="punishment_effective=1" data-vigilance-document="punishment_effective"', false);
        $this->put($this->route('vigilance'), ['vigilance_pending' => '0'])->assertSessionHasErrors(['enquiry_ongoing', 'punishment_effective']);
        // Verified against its original (rule 4.4).
        $this->put($this->route('vigilance'), [...$form, 'vigilance_verified' => '0', 'punishment_document' => $this->pdf()])
            ->assertSessionHasErrors('vigilance_verified');

        // A file sent with a No is not kept.
        $this->put($this->route('vigilance'), [...$form, 'enquiry_document' => $this->pdf('e.pdf'), 'punishment_document' => $this->pdf('p.pdf')])
            ->assertSessionHasNoErrors();

        $row = $this->row->fresh();
        $this->assertFalse($row->enquiry_ongoing);
        $this->assertTrue($row->punishment_effective);
        $this->assertSame([EvaluationDocumentKind::PunishmentDocument->value], $row->currentDocuments()->whereIn('kind', [EvaluationDocumentKind::EnquiryDocument->value, EvaluationDocumentKind::PunishmentDocument->value])->pluck('kind')->map(fn ($kind) => $kind->value)->all());
        $this->assertSame([__('dpc.employee_details.unfit.punishment_effective')], $this->unfit());
        $this->assertSame([], app(EmployeeDetailsService::class)->status($row)['sections']['vigilance']);

        // Both No: nothing asked, nothing missing.
        $this->put($this->route('vigilance'), [...$form, 'punishment_effective' => '0', 'vigilance_verified' => '0'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit());
        $this->assertSame([], app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['vigilance']);
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
        // …and the screen's year list offers it (2026-11-19).
        $this->get($this->route('show'))->assertOk()->assertSee('<option value="'.($last - 5).'"', false);
        $this->post($this->route('cr.save'), ['year' => $last - 3, 'put_up' => '1', 'has_grading' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(range($last, $last - 6), $service->crYearWindow($this->row->fresh()));

        // A year outside the window is refused.
        $this->saveCr($last - 7)->assertSessionHasErrors('cr');
        $this->saveCr($last - 6)->assertSessionHasNoErrors();
    }

    /**
     * 2026-11-16, MoM point 11: a 2026 DPC — 2024 missing counts 2023 in its place and
     * holds nothing; 2025 missing is not Unfit but held for a Circulation DPC.
     */
    public function test_the_latest_cr_missing_goes_to_circulation_and_a_middle_year_is_replaced(): void
    {
        $last = $this->dpcYear - 1;
        $reason = RecordReason::query()->where('code', 'long_leave')->value('id');

        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $last - 1, 'put_up' => '0', 'missing_reason_id' => $reason, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit(), 'A middle year missing is not Unfit.');
        $this->assertSame(range($last, $last - 5), app(EmployeeDetailsService::class)->crYearWindow($this->row->fresh()), 'The next older year is counted in its place.');
        $this->assertNotContains(__('dpc.employee_details.rule.cr_cir', ['year' => $last - 1]), app(PromotionRuleCheck::class)->check($this->row->fresh(), $this->proposal->fresh())['holds']);

        $this->post($this->route('cr.save'), [
            'year' => $last, 'put_up' => '0', 'missing_reason_id' => $reason, 'missing_document' => $this->pdf(),
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unfit(), 'The latest CR missing is not Unfit.');
        $check = app(PromotionRuleCheck::class)->check($this->row->fresh(), $this->proposal->fresh());
        if ($check['status'] !== 'no_rule') {
            $this->assertSame('cir', $check['status']);
            $this->assertContains(__('dpc.employee_details.rule.cr_circulation', ['year' => $last]), $check['holds']);
        }
    }

    /** 2026-11-16: tribal posting is asked with each graded CR year and feeds the per-year bonus. */
    public function test_tribal_posting_is_asked_with_a_graded_cr_year(): void
    {
        $year = $this->dpcYear - 1;
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            // Asked of each CR since 2026-11-18; the year-level answer is still accepted.
            ->assertSee('name="reports[0][tribal_area]"', false)->assertSee(__('dpc.employee_details.cr.q_tribal_cr'));

        $this->saveCr($year, ['tribal_area' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->row->crGrades()->where('year', $year)->value('tribal_area'));
        $this->assertSame([$year => true], OfficerRecord::fromEvaluationRow($this->row->fresh())->tribalAreaByYear);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertSee('data-cr-tribal="'.$year.'"', false);

        $this->saveCr($year, ['tribal_area' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->row->crGrades()->where('year', $year)->value('tribal_area'));

        // Without grading there is nothing to score, so nothing is kept.
        $this->post($this->route('cr.save'), ['year' => $year, 'put_up' => '1', 'has_grading' => '0', 'tribal_area' => '1', 'integrity_option_id' => $this->integrity('certified_good')])->assertSessionHasNoErrors();
        $this->assertNull($this->row->crGrades()->where('year', $year)->value('tribal_area'));
    }

    /** 2026-11-18: each CR of a year answers the tribal question on its own. */
    public function test_each_cr_of_a_year_answers_the_tribal_question(): void
    {
        $year = $this->dpcYear - 1;
        $this->actingAs($this->ao)->post($this->route('cr.save'), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'cr_count' => '2',
            'integrity_option_id' => $this->integrity('certified_good'),
            'reports' => [
                ['authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क', 'tribal_area' => '1'],
                ['authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'ख', 'tribal_area' => '0'],
            ],
            'cr_documents' => [$this->pdf(), $this->pdf()], 'cr_verified' => '1', 'cr_undertaking' => '1',
        ])->assertSessionHasNoErrors();

        $record = $this->row->crGrades()->where('year', $year)->sole();
        $this->assertSame([true, false], array_map(fn (array $report): bool => $report['tribal_area'], (array) $record->reports));
        $this->assertTrue($record->tribal_area, 'The year is tribal when any of its CRs is.');
        $this->assertSame([['grade' => 'क', 'tribal' => true], ['grade' => 'ख', 'tribal' => false]], OfficerRecord::fromEvaluationRow($this->row->fresh())->crReportsByYear[$year]);
    }

    /** 2026-11-16: a year with several CRs uploads each one on its own. */
    public function test_each_cr_of_a_year_is_uploaded_on_its_own(): void
    {
        $year = $this->dpcYear - 1;
        $form = [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'cr_count' => '2',
            'reports' => [
                ['authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क'],
                ['authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'ख'],
            ],
            'integrity_option_id' => $this->integrity('certified_good'),
        ];

        $this->actingAs($this->ao)->post($this->route('cr.save'), [...$form, 'cr_documents' => [0 => $this->pdf('cr-1.pdf')], 'cr_verified' => '1', 'cr_undertaking' => '1'])
            ->assertSessionHasNoErrors();
        $missing = app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr'];
        $this->assertContains(__('dpc.employee_details.missing.cr_document_numbered', ['year' => $year, 'number' => 2]), $missing);
        $this->assertNotContains(__('dpc.employee_details.missing.cr_document_numbered', ['year' => $year, 'number' => 1]), $missing);

        $this->post($this->route('cr.save'), [...$form, 'cr_documents' => [1 => $this->pdf('cr-2.pdf')], 'cr_verified' => '1', 'cr_undertaking' => '1'])
            ->assertSessionHasNoErrors();
        $documents = $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::CrReport->value)->where('year', $year)->orderBy('part')->get();
        $this->assertSame([1, 2], $documents->pluck('part')->all());
        $this->assertSame(['cr-1.pdf', 'cr-2.pdf'], $documents->pluck('original_name')->all());
        $missing = app(EmployeeDetailsService::class)->status($this->row->fresh())['sections']['cr'];
        $this->assertNotContains(__('dpc.employee_details.missing.cr_document_numbered', ['year' => $year, 'number' => 2]), $missing);

        // Replacing CR 2 leaves CR 1 alone.
        $this->post($this->route('cr.save'), [...$form, 'cr_documents' => [1 => $this->pdf('cr-2-new.pdf')], 'cr_verified' => '1', 'cr_undertaking' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['cr-1.pdf', 'cr-2-new.pdf'], $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::CrReport->value)->where('year', $year)->orderBy('part')->pluck('original_name')->all());

        // A file without the confirmations is refused.
        $this->post($this->route('cr.save'), [...$form, 'cr_documents' => [0 => $this->pdf()]])->assertSessionHasErrors(['cr_verified', 'cr_undertaking']);

        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]).'?cr_year='.$year)
            ->assertSee('name="cr_documents[0]"', false)
            ->assertSee('name="cr_documents[1]"', false)
            ->assertSee('data-cr-document-number="'.$year.'-2"', false);
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
            'year' => $this->dpcYear - 6, 'submitted' => '1', 'submitted_on' => ($this->dpcYear - 6).'-01-10', 'next_increment_date' => ($this->dpcYear - 5).'-07-01',
        ])->assertSessionHasErrors('property');
        $this->assertSame(0, $this->row->propertyFlags()->count());
    }

    /** 2026-11-16: the 2025 return is due by 31 January 2026, the 2024 return by 31 January 2025. */
    public function test_a_return_is_late_after_31_january_of_the_next_year(): void
    {
        $year = $this->dpcYear - 2;
        $form = ['year' => $year, 'submitted' => '1', 'next_increment_date' => ($year + 1).'-07-01'];

        // Never before 1 January of the file's own year.
        $this->actingAs($this->ao)->post($this->route('property.save'), [...$form, 'submitted_on' => ($year - 1).'-12-31'])
            ->assertSessionHasErrors(['submitted_on' => __('dpc.employee_details.property.submitted_before_year', ['date' => '01-01-'.$year])]);
        $this->assertSame(0, $this->row->propertyFlags()->count());
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))->assertSee('data-min-from-year', false);

        $this->post($this->route('property.save'), [...$form, 'submitted_on' => ($year + 1).'-01-31'])->assertSessionHasNoErrors();
        $this->assertFalse($this->row->propertyFlags()->sole()->isLate());

        $this->post($this->route('property.save'), [...$form, 'submitted_on' => ($year + 1).'-02-01'])->assertSessionHasErrors('late_decision');

        // The decision's document goes with the year.
        $this->post($this->route('property.save'), [
            ...$form, 'submitted_on' => ($year + 1).'-02-01', 'late_decision' => 'accepted', 'late_decision_remarks' => 'Election duty.',
            'late_document' => $this->pdf('decision.pdf'), 'property_verified' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::PropertyLateDecision->value)->where('year', $year)->count());
    }

    /** 2026-11-16: the same file may be uploaded as the Property Return of two years. */
    public function test_the_same_file_may_be_the_property_return_of_two_years(): void
    {
        $bytes = "%PDF-1.4\nthe same return\n%%EOF\n";
        foreach ([$this->dpcYear - 1, $this->dpcYear - 2] as $year) {
            $file = UploadedFile::fake()->createWithContent('return.pdf', $bytes);
            $this->actingAs($this->ao)->post($this->route('property.save'), [
                'year' => $year, 'submitted' => '1', 'submitted_on' => $year.'-01-10', 'next_increment_date' => ($year + 1).'-01-01',
                'property_document' => $file, 'property_verified' => '1',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $this->row->currentDocuments()->where('kind', EvaluationDocumentKind::PropertyReturn->value)->count());
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
        $this->put($this->route('basic'), [
            'dob_order_number' => 'B/1', 'dob_order_date' => '1970-03-25', 'joining_order_number' => 'J/1', 'joining_order_date' => '1995-07-01',
            'service_record_undertaking' => '1', '_next' => 'https://example.com',
        ])
            ->assertRedirect(route('dpc-proposals.employees.show', [$this->proposal, $this->row]).'#section-basic');
    }
}
