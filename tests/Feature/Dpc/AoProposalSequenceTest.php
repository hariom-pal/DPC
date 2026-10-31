<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcVacancyCalculation;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\ProposalVacancyPosition;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Enums\MasterStatus;
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
 * The AO's DPC proposal on a Vacancy Management record, in order (2026-10-19):
 * 1. Working Officers List reconciled with the finalized record; 2. Seniority List;
 * 3. CR against the Class Master promotion rule; 4. PH declaration with its document;
 * then the existing workflow (submission).
 */
class AoProposalSequenceTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const YEAR = 2026;

    private Department $department;

    private User $ao;

    private DepartmentPost $from;

    private DepartmentPost $to;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        Storage::fake((string) config('dpc.imports.disk', 'local'));
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'registered']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->from = $this->makePost('Block Education Officer', 'II');
        $this->to = $this->makePost('Additional Director, Education', 'I');

        RecruitmentRule::query()->create([
            'post_id' => $this->to->id, 'version' => 1, 'government_order_number' => 'GO/RR/1',
            'direct_recruitment_pct' => 20, 'promotion_recruitment_pct' => 80,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);

        $this->proposal = $this->raiseDpc($this->ao, $this->from, $this->to);
        // Sanctioned 10, working 7: a total vacancy of 3; at the Pre-DPC's 80 % share,
        // 2.4 → a promotion vacancy of 2 (rule 1.3).
        $this->proposal->preDpcProposal->forceFill(['promotion_recruitment_pct' => 80, 'direct_recruitment_pct' => 20])->save();
        $this->finalizeRecord(10, 6, 1, 0);
    }

    private function makePost(string $name, string $class): DepartmentPost
    {
        $post = new DepartmentPost(['department_id' => $this->department->id, 'name' => $name, 'class_level' => $class, 'effective_from' => '2020-01-01']);
        $post->status = MasterStatus::Active;
        $post->active_flag = 1;
        $post->save();

        return $post;
    }

    private function finalizeRecord(int $sanctioned, int $promotion, int $direct, int $deputation): void
    {
        $service = app(VacancyMasterService::class);
        $pdf = fn (): UploadedFile => UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
        $position = $service->openDraft($this->department, $this->to, self::YEAR, $this->ao);
        $service->saveSanctionedStrength($position, ['sanctioned_total' => $sanctioned, 'order_number' => 'SANC/1', 'order_date' => '2026-01-02'], $pdf(), $this->ao);
        // One figure since 2026-10-30: our officers on the post.
        $service->saveWorkingStrength($position->fresh(), [
            'working_total' => $promotion + $direct + $deputation,
            'order_number' => 'EST/1', 'order_date' => '2026-01-02',
        ], $pdf(), $this->ao);
        $service->finalize($position->fresh(), $this->ao);
    }

    /**
     * The Working Officers List as the AO uploads it: one row per working officer by
     * quota, and vacant rows for the rest.
     */
    private function uploadWorkingList(int $promotion, int $direct, int $deputation, int $vacant = 0): TestResponse
    {
        $rows = [['SN', 'STATUS', 'SOURCE', 'NAME', 'DOB', 'CAT']];

        foreach (['promotion' => $promotion, 'direct' => $direct, 'deputation' => $deputation] as $source => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [(string) count($rows), 'working', $source, 'Officer '.count($rows), '01-01-1975', 'UR'];
            }
        }

        for ($i = 0; $i < $vacant; $i++) {
            $rows[] = [(string) count($rows), 'vacant', '', '', '', 'UR'];
        }

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => 'currently_working_us',
            'file' => $this->csv($rows),
        ])->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->latest('id')->firstOrFail();

        return $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $path = tempnam(sys_get_temp_dir(), 'seq').'.csv';
        file_put_contents($path, (string) stream_get_contents($handle));
        fclose($handle);

        return new UploadedFile($path, 'list.csv', 'text/csv', null, true);
    }

    private function vacancySection(): array
    {
        return collect(app(DpcProposalCompletenessService::class)->checklist($this->proposal->refresh()))->firstWhere('key', 'vacancy_master');
    }

    private function seniorityUpload(): TestResponse
    {
        return $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => 'final_seniority_list_us',
            'file' => $this->csv([['SENIORITY NO', 'NAME'], ['1', 'Officer One']]),
            'seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(),
        ]);
    }

    /** Steps 1 → 2 done: a matching list, then two officers on the Seniority List. */
    private function throughSeniority(): DpcEvaluationRow
    {
        $this->uploadWorkingList(6, 1, 0, 3);

        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal->refresh(), [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => $this->from->name],
            ['seniority_number' => 2, 'employee_number' => 'E-2', 'name' => 'Officer Two', 'dob' => '1971-03-20', 'date_of_joining_service' => '1996-07-01', 'promotion_from_post' => $this->from->name],
        ], $this->ao, $this->proposal->seniorityReferenceDate());

        return $this->proposal->evaluationRows()->where('sn', 1)->sole();
    }

    private function crMissing(DpcEvaluationRow $row): array
    {
        return app(EmployeeDetailsService::class)->status($row->refresh())['sections']['cr'];
    }

    // ============================================ 1. Working Officers reconciliation

    public function test_a_list_that_differs_from_the_record_shows_the_differences_and_stops_the_seniority_list(): void
    {
        $this->assertSame(__('vacancymaster.proposal.working_list.section_missing'), $this->vacancySection()['detail']);

        // One officer too many; nobody here on deputation on either side. The record
        // has no quota split since 2026-10-30, so only the total and deputation compare.
        $this->uploadWorkingList(6, 2, 0, 2)->assertSessionHasNoErrors();

        $reconciliation = app(ProposalVacancyPosition::class)->reconcile($this->proposal->refresh());
        $this->assertFalse($reconciliation['matches']);
        $this->assertSame(['total' => ['uploaded' => 8, 'record' => 7]], $reconciliation['differences']);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk()
            ->assertSee('data-reconciled="no"', false)
            ->assertSee('data-working-figure="total" data-differs="yes"', false)
            ->assertSee('data-working-figure="deputation" data-differs="no"', false)
            ->assertDontSee('data-working-figure="direct"', false)
            ->assertSee(__('vacancymaster.proposal.working_list.differs'));

        $this->assertFalse($this->vacancySection()['complete']);
        $this->assertStringContainsString('Total working list 8, record 7', $this->vacancySection()['detail']);

        // Step 2 is closed: the page says why, and the upload and manual save refuse.
        $this->actingAs($this->ao)->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('id="seniority-blocked"', false)
            ->assertDontSee('value="final_seniority_list_us"', false);
        $this->seniorityUpload()->assertSessionHasErrors(['file' => __('vacancymaster.proposal.working_list.seniority_blocked')]);

        config(['dpc.manual_entry.seniority_list' => true]);
        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $this->proposal), [
            'seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(),
            'entries' => [['seniority_number' => 1, 'name' => 'Officer One', 'dob' => '1970-01-01', 'reservation_category_id' => ReservationCategory::query()->value('id')]],
        ])->assertSessionHasErrors('entries');
        $this->assertSame(0, $this->proposal->seniorityEntries()->count());
    }

    public function test_a_matching_list_opens_the_seniority_list(): void
    {
        $this->uploadWorkingList(6, 1, 0, 3)->assertSessionHasNoErrors();

        $this->assertTrue(app(ProposalVacancyPosition::class)->reconcile($this->proposal->refresh())['matches']);
        $this->assertTrue($this->vacancySection()['complete'], $this->vacancySection()['detail']);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertSee('data-reconciled="yes"', false);
        $this->actingAs($this->ao)->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertDontSee('id="seniority-blocked"', false)
            ->assertSee('value="final_seniority_list_us"', false);

        // Past the gate: the upload is judged on its own rules from here.
        $errors = $this->seniorityUpload()->getSession()?->get('errors');
        $this->assertNotContains(__('vacancymaster.proposal.working_list.seniority_blocked'), $errors?->get('file') ?? []);
    }

    public function test_the_vacancy_is_still_calculated_from_the_record_not_the_list(): void
    {
        $before = app(VacancyCalculationService::class)->breakdown($this->proposal->refresh());

        // Officers retiring in the year are on the list; the record's figures stand.
        $this->uploadWorkingList(6, 1, 0, 3);
        $this->proposal->workingOfficers()->where('sn', 1)->update(['date_of_retirement' => '2026-06-30']);

        $after = app(VacancyCalculationService::class)->breakdown($this->proposal->refresh());
        $this->assertSame(2, $after->promotionVacancy);
        $this->assertSame([$before->sanctionedStrength, $before->promotionVacancy, $before->totalVacancy], [$after->sanctionedStrength, $after->promotionVacancy, $after->totalVacancy]);

        // Nothing is derived from the list: vacancy rows are written from the record at submission.
        $this->assertSame(0, $this->proposal->vacancies()->count());
        $this->assertSame(0, DpcVacancyCalculation::query()->count());
    }

    // ===================================== 3. CR against the Class Master promotion rule

    private function classRule(int $min, int $total): void
    {
        $rule = PromotionCrRule::query()->create([
            'to_class' => 'I', 'from_class' => 'II', 'effective_from_year' => self::YEAR,
            'cr_years' => 3, 'loyalty_years' => 0, 'property_years' => 0,
            'qualifying_total' => $total, 'qualifying_average' => round($total / 3, 2), 'super_seat_grade' => 9, 'tribal_bonus' => 0,
        ]);

        // Years 1 and 2 mandatory, year 3 not.
        foreach ([1 => true, 2 => true, 3 => false] as $before => $mandatory) {
            $rule->years()->create(['years_before' => $before, 'cr_min_marks' => $min, 'cr_mandatory' => $mandatory, 'loyalty_mandatory' => false, 'property_mandatory' => false]);
        }

        // The proposal is held to the rule fixed on it.
        $this->proposal->forceFill(['promotion_cr_rule_id' => $rule->id])->save();
    }

    private function saveCr(DpcEvaluationRow $row, int $year, string $grade): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.employees.cr.save', [$this->proposal, $row]), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'reports' => [[
                'reporter_designation' => 'Joint Director', 'reporter_grade' => $grade,
                'reviewer_designation' => 'Director', 'reviewer_grade' => $grade,
                'accepting_designation' => 'Secretary', 'accepting_grade' => $grade,
            ]],
        ])->assertSessionHasNoErrors();
    }

    public function test_the_rules_mandatory_cr_years_are_required_and_its_grades_are_verified(): void
    {
        $this->classRule(min: 7, total: 21);
        $row = $this->throughSeniority();

        $this->assertSame([2025, 2024], app(EmployeeDetailsService::class)->ruleCrYears($row));
        $this->assertSame([
            __('dpc.employee_details.missing.cr_rule_year', ['year' => 2025]),
            __('dpc.employee_details.missing.cr_rule_year', ['year' => 2024]),
        ], $this->crMissing($row));

        $this->saveCr($row, 2025, 'ख');
        $this->assertContains(__('dpc.employee_details.missing.cr_rule_year', ['year' => 2024]), $this->crMissing($row));
        $this->assertNotContains(__('dpc.employee_details.missing.cr_rule_year', ['year' => 2025]), $this->crMissing($row));

        $this->saveCr($row, 2024, 'क');
        $this->assertSame([], array_filter($this->crMissing($row), fn (string $m): bool => str_contains($m, 'promotion rule')));

        // ख (5) is below the minimum 7 and the total 12 below 21: shown while the
        // details are entered; the calculation applies the rule.
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))
            ->assertOk()
            ->assertSee('id="rule-required-years"', false)
            ->assertSee('data-rule-status="does_not_meet"', false)
            ->assertSee(__('dpc.employee_details.rule.cr_below_min', ['year' => 2025, 'points' => 5, 'min' => 7]))
            ->assertSee(__('dpc.employee_details.rule.total_below', ['total' => 12, 'required' => 21]));
        $this->assertSame(2, $this->proposal->evaluationRows()->count());
    }

    public function test_a_rule_with_no_mandatory_year_adds_none_to_the_existing_requirements(): void
    {
        $row = $this->throughSeniority();

        $this->assertSame([], app(EmployeeDetailsService::class)->ruleCrYears($row));
        $this->assertSame([__('dpc.employee_details.missing.cr_none')], $this->crMissing($row));
    }

    // =========================================== 4. PH declaration and its document

    public function test_every_ph_employee_needs_one_document_and_nothing_is_granted(): void
    {
        $row = $this->throughSeniority();
        $answer = function (array $answers) use ($row): PhReservationOutcome {
            $this->actingAs($this->ao)->put(route('dpc-proposals.employees.ph', [$this->proposal, $row]), $answers)->assertSessionHasNoErrors();

            return $row->refresh()->phOutcome();
        };
        $ph = fn (): array => app(EmployeeDetailsService::class)->status($row->refresh())['sections']['ph'];
        $needsDocument = [__('dpc.employee_details.missing.ph_undertaking')];

        // Q1 No: no further question, no document.
        $this->assertSame(PhReservationOutcome::NotPh, $answer(['is_ph' => '0']));
        $this->assertSame([], $ph());

        // Q1 Yes, Q2 Yes (availed before): a document.
        $this->assertSame(PhReservationOutcome::PreviouslyAvailed, $answer(['is_ph' => '1', 'ph_previously_availed' => '1']));
        $this->assertSame($needsDocument, $ph());

        // Q2 No, Q3 No and Q3 Yes: a document either way.
        $this->assertSame(PhReservationOutcome::Declined, $answer(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '0']));
        $this->assertSame($needsDocument, $ph());
        $this->assertSame(PhReservationOutcome::Claimed, $answer(['is_ph' => '1', 'ph_previously_availed' => '0', 'ph_claims_reservation' => '1']));
        $this->assertSame($needsDocument, $ph());

        $this->actingAs($this->ao)->post(route('dpc-proposals.employees.documents.store', ['verified' => '1', $this->proposal, $row]), [
            'kind' => EvaluationDocumentKind::PhUndertaking->value, 'undertaking' => '1',
            'document' => UploadedFile::fake()->create('ph.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $ph());
        $this->assertSame('pending', $row->refresh()->ph_verification_status, 'A claim still goes to verification.');
        $this->assertNull($row->ph_final_decision, 'The answers and the document grant nothing.');
    }

    // ============================================== 5. The existing workflow follows

    public function test_after_the_four_steps_the_proposal_submits_on_the_records_vacancy(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();

        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);
        $this->assertSame(2, (int) $this->proposal->vacancies()->sum('count'));
    }
}
