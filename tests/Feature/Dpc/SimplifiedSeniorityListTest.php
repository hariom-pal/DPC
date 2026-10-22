<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Import\Services\ImportTemplateBuilder;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The simplified Seniority List (Coder Instructions, rule 1.6; business decision of
 * 2026-10-17): six columns, the "as on" date as proposal metadata, row-level and
 * cross-row validation, and a re-import that keeps every officer's recorded details.
 */
class SimplifiedSeniorityListTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const HEADER = ['SENIORITY NO', 'EMPLOYEE ID', 'EMPLOYEE NAME', 'DATE OF BIRTH', 'DATE OF APPOINTMENT', 'CURRENT POST'];

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake('local');

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $from = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
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
        $path = tempnam(sys_get_temp_dir(), 'snr').'.csv';
        file_put_contents($path, (string) stream_get_contents($handle));
        fclose($handle);

        return new UploadedFile($path, 'seniority.csv', 'text/csv', null, true);
    }

    /**
     * @param  list<list<string>>  $rows  data rows only
     */
    private function upload(array $rows, ?string $asOn = 'reference'): ImportBatch
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->csv([self::HEADER, ...$rows]),
            'seniority_list_as_on' => $asOn === 'reference' ? $this->proposal->seniorityReferenceDate()->toDateString() : $asOn,
        ]);

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    private function commit(ImportBatch $batch): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));
    }

    /**
     * @return list<string>
     */
    private function officer(int $sn, string $employee, string $name): array
    {
        return [(string) $sn, $employee, $name, '20-03-1970', '01-07-1995', 'Under Secretary'];
    }

    // ------------------------------------------------------------- the cutoff

    public function test_the_cutoff_is_1_april_of_the_year_before_the_dpc_year(): void
    {
        $this->assertSame(2026, $this->proposal->dpc_year);
        $this->assertSame('2025-04-01', $this->proposal->seniorityReferenceDate()->toDateString());

        $this->proposal->dpc_year = 2027;
        $this->assertSame('2026-04-01', $this->proposal->seniorityReferenceDate()->toDateString());
    }

    public function test_the_cutoff_does_not_follow_the_current_date(): void
    {
        Carbon::setTestNow('2031-11-20 10:00:00');

        $this->assertSame('2025-04-01', $this->proposal->refresh()->seniorityReferenceDate()->toDateString());

        Carbon::setTestNow();
    }

    public function test_the_screen_shows_the_required_date(): void
    {
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.seniority-list', $this->proposal))
            ->assertOk()
            ->assertSee('01-04-2025')
            ->assertSee('name="seniority_list_as_on"', false);
    }

    // ----------------------------------------------------------- the template

    public function test_the_template_carries_only_the_simple_columns(): void
    {
        $headers = app(ImportTemplateBuilder::class)->headers(ImportTemplate::FinalSeniorityListUs);

        $this->assertSame([...self::HEADER, 'DATE OF POSTING ON CURRENT POST'], $headers);

        foreach ($headers as $header) {
            $this->assertDoesNotMatchRegularExpression('/\b(CR|PROPERTY|LOYALTY|PH|CAT|CATEGORY)\b/', $header);
        }
    }

    public function test_the_downloadable_template_names_this_dpcs_current_post(): void
    {
        $content = (string) $this->actingAs($this->ao)
            ->get(route('dpc-import-templates.download-for', ['dpcProposal' => $this->proposal, 'template' => ImportTemplate::FinalSeniorityListUs->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"SENIORITY NO","EMPLOYEE ID","EMPLOYEE NAME","DATE OF BIRTH","DATE OF APPOINTMENT","CURRENT POST"', $content);
        $this->assertStringContainsString('Under Secretary', $content);
    }

    // ------------------------------------------------------------- the import

    public function test_a_simple_csv_is_accepted_without_cr_property_ph_or_category_columns(): void
    {
        $batch = $this->upload([
            $this->officer(1, 'E-1', 'Officer One'),
            $this->officer(2, 'E-2', 'Officer Two'),
        ]);

        $this->assertSame(ImportBatchStatus::Previewed, $batch->status);
        $this->assertSame(0, $batch->rejected_count, implode(' | ', $batch->rejectedRows()->get()->flatMap->errorMessages()->all()));
        // The preview writes nothing.
        $this->assertSame(0, $this->proposal->seniorityEntries()->count());

        $this->commit($batch);

        $entries = $this->proposal->refresh()->seniorityEntries()->get();
        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]->reservation_category_id);
        $this->assertSame('Under Secretary', $entries[0]->promotion_from_post);
        $this->assertSame('Deputy Secretary', $entries[0]->promotion_to_post, 'The promotion post is filled from the proposal.');
        $this->assertSame('1995-07-01', $entries[0]->date_of_joining_service?->toDateString());
        $this->assertSame('2025-04-01', $this->proposal->seniority_list_as_on?->toDateString());

        // The chart rows are projected, with no CR, Property or PH data.
        $this->assertSame(2, $this->proposal->evaluationRows()->count());
        $this->assertSame(0, DpcEvaluationRow::query()->whereNotNull('is_ph')->count());
    }

    public function test_the_preview_screen_renders_the_simple_rows(): void
    {
        $batch = $this->upload([$this->officer(1, 'E-1', 'Officer One')]);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$this->proposal, $batch]))
            ->assertOk()
            ->assertSee('Officer One')
            ->assertSee('E-1');
    }

    public function test_a_list_stated_as_on_another_date_is_refused(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->csv([self::HEADER, $this->officer(1, 'E-1', 'Officer One')]),
            'seniority_list_as_on' => '2024-04-01',
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, ImportBatch::query()->count(), 'Nothing is staged for a list drawn up on the wrong date.');
    }

    public function test_a_list_without_its_as_on_date_is_refused(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->csv([self::HEADER, $this->officer(1, 'E-1', 'Officer One')]),
        ])->assertSessionHasErrors('seniority_list_as_on');
    }

    public function test_duplicate_employee_ids_are_rejected_on_every_row_involved(): void
    {
        $batch = $this->upload([
            $this->officer(1, 'E-1', 'Officer One'),
            $this->officer(2, 'e-1', 'Officer Two'),
            $this->officer(3, 'E-3', 'Officer Three'),
        ]);

        $this->assertSame(2, $batch->rejected_count);
        $this->assertStringContainsString('appears more than once', implode(' ', $batch->rejectedRows()->first()->errorMessages()));
    }

    public function test_duplicate_seniority_numbers_are_rejected(): void
    {
        $batch = $this->upload([
            $this->officer(1, 'E-1', 'Officer One'),
            $this->officer(1, 'E-2', 'Officer Two'),
        ]);

        $this->assertSame(2, $batch->rejected_count);
        $this->assertStringContainsString('Seniority number 1', implode(' ', $batch->rejectedRows()->first()->errorMessages()));
    }

    public function test_a_current_post_other_than_this_dpcs_is_rejected(): void
    {
        $row = $this->officer(1, 'E-1', 'Officer One');
        $row[5] = 'Section Officer';

        $batch = $this->upload([$row]);

        $this->assertSame(1, $batch->rejected_count);
        $this->assertStringContainsString('Section Officer', implode(' ', $batch->rejectedRows()->sole()->errorMessages()));
    }

    public function test_employee_id_and_date_of_appointment_are_required(): void
    {
        $batch = $this->upload([['1', '', 'Officer One', '20-03-1970', '', 'Under Secretary']]);

        $messages = implode(' ', $batch->rejectedRows()->sole()->errorMessages());
        $this->assertStringContainsString('employee number', strtolower($messages));
        $this->assertStringContainsString('date of joining service', strtolower($messages));
    }

    public function test_a_file_with_rejected_rows_is_not_committed(): void
    {
        $this->commit($this->upload([$this->officer(1, 'E-1', 'Officer One')]));

        $batch = $this->upload([
            $this->officer(1, 'E-1', 'Officer One'),
            $this->officer(2, 'E-1', 'Officer Duplicate'),
        ]);

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))
            ->assertSessionHasErrors('commit');

        $this->assertSame(['Officer One'], $this->proposal->seniorityEntries()->pluck('name')->all(), 'The existing list is untouched.');
    }

    // --------------------------------------------------- re-import is safe

    public function test_a_re_import_keeps_each_officers_details_and_detaches_only_the_removed(): void
    {
        $this->commit($this->upload([
            $this->officer(1, 'E-1', 'Officer One'),
            $this->officer(2, 'E-2', 'Officer Two'),
            $this->officer(3, 'E-3', 'Officer Three'),
        ]));

        $two = $this->proposal->evaluationRows()->where('employee_number', 'E-2')->sole();
        $three = $this->proposal->evaluationRows()->where('employee_number', 'E-3')->sole();
        $details = app(EmployeeDetailsService::class);

        $details->savePh($two, ['is_ph' => true, 'ph_previously_availed' => false, 'ph_claims_reservation' => true], $this->ao);
        $details->saveCrYear($two, 2023, ['put_up' => false], $this->ao);
        DpcEvaluationRecordGap::query()->create([
            'dpc_evaluation_row_id' => $two->id, 'kind' => 'cr', 'year' => 2023,
            'record_reason_id' => RecordReason::query()->value('id'),
            'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        ]);
        $details->upload($three, EvaluationDocumentKind::Other, null, UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'), false, 'A note', $this->ao, true);

        // Re-ordered: E-2 moves to 1, E-3 is dropped, E-4 joins.
        $batch = $this->upload([
            $this->officer(1, 'E-2', 'Officer Two'),
            $this->officer(2, 'E-1', 'Officer One'),
            $this->officer(3, 'E-4', 'Officer Four'),
        ]);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$this->proposal, $batch]))
            ->assertOk()
            ->assertSee('E-3 Officer Three');

        $this->commit($batch);

        $twoAfter = $this->proposal->evaluationRows()->where('employee_number', 'E-2')->sole();
        $this->assertSame($two->id, $twoAfter->id, 'The officer keeps their row.');
        $this->assertSame(1, $twoAfter->sn);
        $this->assertTrue($twoAfter->ph_claims_reservation);
        $this->assertSame(1, $twoAfter->crGrades()->where('year', 2023)->count());
        $this->assertSame(1, $twoAfter->recordGaps()->count(), 'A missing-record justification survives the re-import.');

        $this->assertNull(DpcEvaluationRow::query()->find($three->id));
        $document = DpcEvaluationDocument::query()->where('employee_number', 'E-3')->sole();
        $this->assertNull($document->dpc_evaluation_row_id, 'The removed officer\'s document is kept, unlinked.');

        // Corrected back: the document finds the officer again.
        $this->commit($this->upload([
            $this->officer(1, 'E-2', 'Officer Two'),
            $this->officer(2, 'E-1', 'Officer One'),
            $this->officer(3, 'E-3', 'Officer Three'),
        ]));

        $threeAgain = $this->proposal->evaluationRows()->where('employee_number', 'E-3')->sole();
        $this->assertSame($threeAgain->id, $document->refresh()->dpc_evaluation_row_id);
    }

    public function test_a_category_recorded_on_the_details_survives_a_re_import_that_carries_none(): void
    {
        $this->commit($this->upload([$this->officer(1, 'E-1', 'Officer One')]));

        $row = $this->proposal->evaluationRows()->sole();
        $row->forceFill(['reservation_category_id' => ReservationCategory::query()->value('id')])->save();

        $this->commit($this->upload([$this->officer(1, 'E-1', 'Officer One')]));

        $this->assertNotNull($row->refresh()->reservation_category_id);
    }

    // ------------------------------------------------------- the gate

    public function test_the_submission_gate_requires_the_as_on_date_for_the_current_dpc_year(): void
    {
        $this->commit($this->upload([$this->officer(1, 'E-1', 'Officer One')]));
        $gate = app(DpcProposalCompletenessService::class);

        $section = fn (): array => collect($gate->checklist($this->proposal->refresh()))->firstWhere('key', 'seniority_list');

        $this->assertStringNotContainsString('as on', $section()['detail']);

        // The DPC year is edited after the list was saved.
        $this->proposal->forceFill(['dpc_year' => 2027])->save();

        $this->assertFalse($section()['complete']);
        $this->assertStringContainsString('01-04-2026', $section()['detail']);

        $this->proposal->forceFill(['seniority_list_as_on' => null, 'dpc_year' => 2026])->save();
        $this->assertFalse($section()['complete']);
    }
}
