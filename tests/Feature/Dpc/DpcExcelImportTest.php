<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

/**
 * Excel import (PPT S16–S18 upload templates) — M4 Phase 6.
 *
 * Covers the architect-directed pipeline end to end: Preview -> Validation ->
 * Error Report -> Commit, plus the guarantee that nothing reaches a proposal
 * before an explicit commit.
 */
class DpcExcelImportTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows, string $name = 'upload.csv'): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function upload(ImportTemplate $template, UploadedFile $file): ImportBatch
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => $template->value,
            'file' => $file,
        ]);

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    private function seniorityFile(): UploadedFile
    {
        return $this->csv([
            ['SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'CAT', 'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON US'],
            ['1', 'EMP-1', 'US1', '20-03-1964', '11TH', 'UR', '01-04-1985', '01-01-2012'],
            ['2', 'EMP-2', 'US2', '12-05-1968', '11TH', 'OBC', '13-03-1986', '01-01-2012'],
        ]);
    }

    public function test_upload_previews_without_writing_anything_to_the_proposal(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->assertSame(ImportBatchStatus::Previewed, $batch->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(0, $batch->rejected_count);
        $this->assertSame(0, $batch->imported_count);

        // The whole point of the preview stage.
        $this->assertSame(0, $this->proposal->seniorityEntries()->count());
    }

    public function test_commit_writes_through_the_existing_service_layer(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();
        $this->assertSame(2, $this->proposal->seniorityEntries()->count());

        $first = $this->proposal->seniorityEntries()->where('seniority_number', 1)->firstOrFail();
        $this->assertSame('US1', $first->name);
        $this->assertSame('EMP-1', $first->employee_number);
        $this->assertSame('1964-03-20', $first->dob->format('Y-m-d'));
        // "DATE OF POSTING ON US" (S17) is the feeder-post posting date.
        $this->assertSame('2012-01-01', $first->date_of_posting_on_feeder_post?->format('Y-m-d'));

        $batch->refresh();
        $this->assertSame(ImportBatchStatus::Committed, $batch->status);
        $this->assertSame(2, $batch->imported_count);
    }

    public function test_dates_are_read_as_day_first_matching_the_ppt_templates(): void
    {
        // "01-02-2012" must be 1 February, not 2 January.
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT', 'DATE OF POSTING ON US'],
            ['1', 'US1', '20-03-1964', 'UR', '01-02-2012'],
        ]));

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));

        $entry = $this->proposal->seniorityEntries()->firstOrFail();
        $this->assertSame('2012-02-01', $entry->date_of_posting_on_feeder_post?->format('Y-m-d'));
    }

    public function test_invalid_rows_are_rejected_individually_and_the_valid_ones_still_commit(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT'],
            ['1', 'US1', '20-03-1964', 'UR'],
            ['2', '', '12-05-1968', 'OBC'],          // missing name
            ['3', 'US3', '09-04-1967', 'BOGUS'],     // unknown category code
            ['4', 'US4', 'not-a-date', 'UR'],        // unreadable date
        ]));

        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(3, $batch->rejected_count);

        $rejected = $batch->rejectedRows()->get();
        $this->assertStringContainsString('BOGUS', implode(' ', $rejected->firstWhere('row_number', 4)->errorMessages()));
        $this->assertStringContainsString('date', strtolower(implode(' ', $rejected->firstWhere('row_number', 5)->errorMessages())));

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));

        $this->assertSame(1, $this->proposal->seniorityEntries()->count());
        $this->assertSame(1, $batch->refresh()->imported_count);
    }

    public function test_error_report_downloads_as_csv_and_neutralises_formula_injection(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT'],
            ['1', '=cmd|/c calc!A1', '20-03-1964', 'BOGUS'],
        ]));

        $response = $this->actingAs($this->ao)->get(route('dpc-proposals.imports.errors', [$this->proposal, $batch]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));

        $body = (string) $response->getContent();
        // The dangerous cell is present but defanged with a leading quote.
        $this->assertStringContainsString("'=cmd", (string) $body);
    }

    public function test_evaluation_chart_year_columns_are_parsed_by_pattern(): void
    {
        $batch = $this->upload(ImportTemplate::EvaluationChartUs, $this->csv([
            ['SN', 'NAME OF US', 'DOB', 'QUAL', 'DOP', 'CAT', 'LOYALTY 2016', 'LOYALTY 2017', 'PROPERTY 2016', 'PROPERTY 2017', 'CR 2016', 'CR 2017', 'NOTE'],
            ['1', 'US1', '20-03-1964', '11TH', '01-01-2012', 'UR', 'Y', 'N', 'Y', 'Y', 'क+', 'क', 'PUNISHMENT'],
        ]));

        $this->assertSame(0, $batch->rejected_count, 'Expected the S18 row to validate: '.json_encode($batch->rows()->get()->pluck('errors')));

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));

        $row = $this->proposal->evaluationRows()->firstOrFail();
        $this->assertSame('PUNISHMENT', $row->note);
        $this->assertSame('2012-01-01', $row->date_of_posting_on_feeder_post?->format('Y-m-d'));

        $this->assertSame(2, $row->crGrades()->count());
        $this->assertSame('क+', $row->crGrades()->where('year', 2016)->firstOrFail()->grade);
        $this->assertTrue($row->loyaltyFlags()->where('year', 2016)->firstOrFail()->flag);
        $this->assertFalse($row->loyaltyFlags()->where('year', 2017)->firstOrFail()->flag);
    }

    public function test_working_officers_template_commits_to_its_own_section(): void
    {
        $batch = $this->upload(ImportTemplate::CurrentlyWorkingUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT', 'DATE OF POSTING ON POST', 'ROSTER POSITION'],
            ['1', 'DS1', '20-03-1972', 'UR', '15-07-2019', '3'],
        ]));

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));

        $officer = $this->proposal->workingOfficers()->firstOrFail();
        $this->assertSame('DS1', $officer->name);
        $this->assertSame(3, $officer->roster_position);
        $this->assertSame('2019-07-15', $officer->date_of_posting_on_post?->format('Y-m-d'));
    }

    public function test_a_batch_cannot_be_committed_twice(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))
            ->assertSessionHasErrors('commit');

        $this->assertSame(2, $this->proposal->seniorityEntries()->count());
    }

    public function test_cancelled_batch_cannot_be_committed(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.cancel', [$this->proposal, $batch]));
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))
            ->assertSessionHasErrors('commit');

        $this->assertSame(0, $this->proposal->seniorityEntries()->count());
    }

    public function test_import_is_blocked_once_the_proposal_is_submitted(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))
            ->assertForbidden();

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->seniorityFile(),
        ])->assertForbidden();

        // The batch never left preview, and none of its officers reached the
        // submitted proposal's seniority list.
        $this->assertSame(ImportBatchStatus::Previewed, $batch->refresh()->status);
        $this->assertSame(0, $this->proposal->seniorityEntries()->whereIn('name', ['US1', 'US2'])->count());
    }

    public function test_another_departments_officer_cannot_reach_a_batch(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->create(['department_id' => $other->id]);
        $intruder->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($intruder)->get(route('dpc-proposals.imports.show', [$this->proposal, $batch]))->assertForbidden();
        $this->actingAs($intruder)->get(route('dpc-proposals.imports.errors', [$this->proposal, $batch]))->assertForbidden();
        $this->actingAs($intruder)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))->assertForbidden();
    }

    public function test_a_batch_from_another_proposal_is_not_reachable_through_this_one(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->proposal->post_to_cadre_id,
            'post_from_cadre_id' => $this->proposal->post_from_cadre_id,
            'proposal_date' => '2026-06-26',
        ]);
        $otherProposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$otherProposal, $batch]))
            ->assertNotFound();
    }

    public function test_a_file_with_no_recognisable_headers_is_rejected_with_guidance(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => $this->csv([['alpha', 'beta'], ['1', '2']]),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, $this->proposal->seniorityEntries()->count());
    }

    public function test_non_spreadsheet_uploads_are_rejected(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => ImportTemplate::FinalSeniorityListUs->value,
            'file' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
        ])->assertSessionHasErrors('file');
    }

    public function test_preview_and_commit_each_record_a_timeline_entry(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));

        $events = ActivityTimeline::query()
            ->where('subject_type', $this->proposal->getMorphClass())
            ->where('subject_id', $this->proposal->id)
            ->pluck('event');

        $this->assertContains('dpc_proposal.import_previewed', $events);
        $this->assertContains('dpc_proposal.import_committed', $events);
        // Decision 4: the section save itself is on the timeline too.
        $this->assertContains('dpc_proposal.seniority_list_updated', $events);
    }

    public function test_every_batch_retains_the_original_file_its_hash_and_its_error_report(): void
    {
        // Architect directives: preserve the original upload, the CSV error
        // report, and the import log permanently; hash every uploaded file.
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT'],
            ['1', 'US1', '20-03-1964', 'UR'],
            ['2', 'US2', '12-05-1968', 'BOGUS'],
        ]));

        $disk = Storage::disk('local');

        $this->assertNotNull($batch->file_hash);
        $this->assertSame(64, strlen((string) $batch->file_hash));
        $this->assertTrue($disk->exists($batch->stored_path), 'The original upload must be retained.');

        $this->assertNotNull($batch->error_report_path);
        $this->assertTrue($disk->exists((string) $batch->error_report_path), 'The error report must be retained.');
        $this->assertStringContainsString('BOGUS', (string) $disk->get((string) $batch->error_report_path));

        // Import log retained for every row, valid and rejected alike.
        $this->assertSame(2, $batch->rows()->count());

        // Committing must not delete any of the evidence.
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]));
        $batch->refresh();

        $this->assertTrue($disk->exists($batch->stored_path));
        $this->assertTrue($disk->exists((string) $batch->error_report_path));
        $this->assertSame(2, $batch->rows()->count());
    }

    public function test_uploading_the_same_file_twice_is_flagged_as_a_duplicate(): void
    {
        $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());
        $second = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$this->proposal, $second]))
            ->assertOk()
            ->assertSee('This exact file has been uploaded before');
    }

    public function test_a_tampered_stored_file_fails_the_integrity_check(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        Storage::disk('local')->put($batch->stored_path, 'tampered');

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$this->proposal, $batch]))
            ->assertOk()
            ->assertSee('File integrity check failed');
    }

    public function test_downloaded_error_report_is_the_retained_artefact(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->csv([
            ['SN', 'NAME', 'DOB', 'CAT'],
            ['1', 'US1', '20-03-1964', 'BOGUS'],
        ]));

        $stored = (string) Storage::disk('local')->get((string) $batch->error_report_path);
        $downloaded = (string) $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.errors', [$this->proposal, $batch]))
            ->getContent();

        $this->assertSame($stored, $downloaded);
    }

    public function test_preview_screen_renders(): void
    {
        $batch = $this->upload(ImportTemplate::FinalSeniorityListUs, $this->seniorityFile());

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.imports.show', [$this->proposal, $batch]))
            ->assertOk()
            ->assertSee('Import Preview')
            ->assertSee('US1');
    }
}
