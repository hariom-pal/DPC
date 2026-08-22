<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EvaluationCandidateService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Validation\DpcRowRules;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Import\Support\RawSheetImport;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Excel import for the three PPT upload templates (S16–S18) — M4 Phase 6.
 *
 * Implements the architect-directed pipeline: **Preview -> Validation ->
 * Error Report -> Commit**. Uploading parses and validates the file into an
 * `ImportBatch` of staged `ImportRow`s and writes nothing to the proposal;
 * only an explicit commit of a previewed batch does that, and it does so by
 * calling the existing `DpcProposalService` save methods — the same methods
 * manual entry uses. There is no second copy of the persistence logic, and
 * validation rules come from `DpcRowRules`, shared with the manual-entry Form
 * Requests (architect directive: never duplicate business logic).
 *
 * A commit is all-or-nothing on the *valid* rows: rejected rows are reported,
 * never silently dropped into a partial write. Because the underlying save
 * methods replace a section wholesale, committing a batch replaces that
 * section rather than appending to it — stated plainly on the preview screen,
 * since that is the one behaviour an officer could otherwise mistake.
 */
class ExcelImportService
{
    public function __construct(
        private readonly DpcProposalService $proposals,
        private readonly ActivityTimelineService $timeline,
        private readonly VacancyCalculationService $vacancies,
        private readonly EvaluationCandidateService $candidates,
    ) {}

    /**
     * Stage 1–3: parse the upload, validate every row, and stage the result
     * for review. Nothing reaches the proposal here.
     */
    public function preview(DpcProposal $proposal, ImportTemplate $template, UploadedFile $file, User $actor): ImportBatch
    {
        $this->assertProposalEditable($proposal);

        // Hashed before storing: the digest identifies exactly the bytes the
        // department uploaded (architect directive — duplicate detection,
        // evidence, integrity, audit).
        $hash = hash_file('sha256', $file->getRealPath());

        $path = $file->store('imports/'.$proposal->id, config('dpc.imports.disk'));

        $batch = ImportBatch::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'template' => $template->value,
            'status' => ImportBatchStatus::Pending->value,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => (string) $path,
            'file_hash' => $hash === false ? null : $hash,
            'uploaded_by' => $actor->id,
        ]);

        try {
            $parsed = $this->parse($template, (string) $path);
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => ImportBatchStatus::Failed->value,
                'total_rows' => 0,
            ])->save();

            throw new WorkflowException('The file could not be read as a spreadsheet: '.$e->getMessage());
        }

        $valid = 0;
        $rejected = 0;

        DB::transaction(function () use ($batch, $template, $parsed, &$valid, &$rejected): void {
            foreach ($parsed as $row) {
                $errors = $this->validateRow($template, $row['payload'], $row['errors']);
                $isValid = $errors === [];

                $batch->rows()->create([
                    'row_number' => $row['row_number'],
                    'payload' => $row['payload'],
                    'errors' => $isValid ? null : $errors,
                    'is_valid' => $isValid,
                ]);

                $isValid ? $valid++ : $rejected++;
            }

            $batch->forceFill([
                'status' => ImportBatchStatus::Previewed->value,
                'total_rows' => count($parsed),
                'imported_count' => 0,
                'rejected_count' => $rejected,
                'previewed_at' => now(),
            ])->save();
        });

        // Persisted at validation time, not rendered on demand: the officer
        // downloads the same artefact the batch was judged against, even if
        // validation rules change later (architect directive — preserve the
        // error report for every batch).
        if ($rejected > 0) {
            $reportPath = "imports/{$proposal->id}/error-report-batch-{$batch->id}.csv";
            Storage::disk(config('dpc.imports.disk'))->put($reportPath, $this->buildErrorReportCsv($batch->fresh(['rows']) ?? $batch));
            $batch->forceFill(['error_report_path' => $reportPath])->save();
        }

        $this->timeline->record(
            'dpc_proposal.import_previewed',
            $proposal,
            "{$template->label()} file previewed: {$valid} valid, {$rejected} rejected",
            ['batch_id' => $batch->id, 'template' => $template->value, 'valid' => $valid, 'rejected' => $rejected],
            $actor,
        );

        return $batch->fresh(['rows']) ?? $batch;
    }

    /**
     * Stage 4: hand the valid rows to the existing service layer.
     */
    public function commit(ImportBatch $batch, User $actor): ImportBatch
    {
        $proposal = $batch->proposal()->firstOrFail();
        $this->assertProposalEditable($proposal);

        if (! $batch->status->isCommittable()) {
            throw new WorkflowException('This import can no longer be committed — it is '.$batch->status->label().'.');
        }

        /** @var list<array<string, mixed>> $payloads */
        $payloads = $batch->validRows()->get()
            ->map(fn ($row): array => $row->payload ?? [])
            ->all();

        if ($payloads === []) {
            throw new WorkflowException('This import has no valid rows to commit. Fix the rejected rows and upload the file again.');
        }

        $template = $batch->template;

        $this->assertSectionInvariants($template, $proposal, $payloads);

        DB::transaction(function () use ($template, $proposal, $payloads, $actor, $batch): void {
            match ($template) {
                ImportTemplate::CurrentlyWorkingUs => $this->proposals->saveWorkingOfficers($proposal, $payloads, $actor),
                ImportTemplate::FinalSeniorityListUs => $this->proposals->saveSeniorityEntries($proposal, $payloads, $actor),
                ImportTemplate::EvaluationChartUs => $this->proposals->saveEvaluationRows($proposal, $payloads, $actor),
            };

            $batch->forceFill([
                'status' => ImportBatchStatus::Committed->value,
                'imported_count' => count($payloads),
                'committed_by' => $actor->id,
                'committed_at' => now(),
            ])->save();
        });

        $this->timeline->record(
            'dpc_proposal.import_committed',
            $proposal,
            "{$template->label()} import committed: ".count($payloads).' rows',
            ['batch_id' => $batch->id, 'template' => $template->value, 'imported' => count($payloads), 'rejected' => $batch->rejected_count],
            $actor,
        );

        return $batch->refresh();
    }

    public function cancel(ImportBatch $batch, User $actor): ImportBatch
    {
        if ($batch->status->isTerminal()) {
            throw new WorkflowException('This import is already '.$batch->status->label().'.');
        }

        $batch->forceFill([
            'status' => ImportBatchStatus::Cancelled->value,
            'cancelled_at' => now(),
        ])->save();

        $this->timeline->record(
            'dpc_proposal.import_cancelled',
            $batch->proposal()->first(),
            "{$batch->template->label()} import cancelled",
            ['batch_id' => $batch->id],
            $actor,
        );

        return $batch;
    }

    /**
     * Stage 3 output: a CSV of every rejected row and why it was rejected.
     *
     * Cells are escaped against CSV/formula injection — an error report is a
     * file the officer opens in Excel, and it echoes back content that came
     * from an untrusted upload.
     */
    public function errorReportCsv(ImportBatch $batch): string
    {
        $disk = Storage::disk(config('dpc.imports.disk'));

        if ($batch->error_report_path !== null && $disk->exists($batch->error_report_path)) {
            return (string) $disk->get($batch->error_report_path);
        }

        // Batches created before the report was persisted, and batches with
        // nothing rejected, render from the retained import log.
        return $this->buildErrorReportCsv($batch);
    }

    /**
     * Verify a retained upload still matches the SHA-256 recorded when it was
     * received (architect directive — file integrity / evidence).
     */
    public function verifyStoredFileIntegrity(ImportBatch $batch): bool
    {
        $disk = Storage::disk(config('dpc.imports.disk'));

        if ($batch->file_hash === null || ! $disk->exists($batch->stored_path)) {
            return false;
        }

        return hash('sha256', (string) $disk->get($batch->stored_path)) === $batch->file_hash;
    }

    /**
     * Earlier batches of the same template whose uploaded bytes are identical
     * (architect directive — duplicate detection). Surfaced on the preview
     * screen rather than blocking: re-uploading the same file is sometimes
     * deliberate, but doing it unknowingly is the mistake worth catching.
     *
     * @return Collection<int, ImportBatch>
     */
    public function duplicatesOf(ImportBatch $batch): Collection
    {
        if ($batch->file_hash === null) {
            return ImportBatch::query()->whereRaw('1 = 0')->get();
        }

        return ImportBatch::query()
            ->where('dpc_proposal_id', $batch->dpc_proposal_id)
            ->where('template', $batch->template->value)
            ->where('file_hash', $batch->file_hash)
            ->where('id', '!=', $batch->id)
            ->with('uploader')
            ->orderBy('id')
            ->get();
    }

    private function buildErrorReportCsv(ImportBatch $batch): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new WorkflowException('Could not generate the error report.');
        }

        fputcsv($handle, ['Row Number', 'Name', 'Errors']);

        foreach ($batch->rejectedRows()->get() as $row) {
            $payload = $row->payload ?? [];

            fputcsv($handle, [
                $this->csvSafe((string) $row->row_number),
                $this->csvSafe((string) ($payload['name'] ?? '')),
                $this->csvSafe(implode(' | ', $row->errorMessages())),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A leading =, +, -, @ or control character makes a spreadsheet treat the
     * cell as a formula when the report is opened. Prefixing with a single
     * quote neutralises that without changing the visible text.
     */
    private function csvSafe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * The section invariants that a Form Request would have applied, on the
     * import path (business directives, 2026-08-22).
     *
     * **This is here because an import does not pass through a Form Request.**
     * `commit()` hands its rows straight to the Domain Service, so
     * `SaveWorkingOfficersRequest` and `SaveSeniorityEntriesRequest` never see
     * them. Without this, uploading a 12-officer chart against a sanctioned
     * strength of 10 would succeed — and the rule that a manual save refuses
     * would be one file upload away from being bypassed entirely.
     *
     * Refused *before* the transaction that writes the rows, so a rejected
     * import leaves the proposal exactly as it was rather than half-replaced.
     *
     * All three sections are checked, each by its own rule: the officer chart
     * against the sanctioned strength, the Seniority List against the Zone of
     * Consideration, and the Evaluation Chart against the Seniority List.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    private function assertSectionInvariants(ImportTemplate $template, DpcProposal $proposal, array $payloads): void
    {
        match ($template) {
            ImportTemplate::CurrentlyWorkingUs => $this->assertWithinSanctionedStrength($proposal, count($payloads)),
            ImportTemplate::FinalSeniorityListUs => $this->assertMatchesZone($proposal, count($payloads)),
            // The officer set must be the Seniority List's (business directive,
            // 2026-08-22). Asserted here, before the transaction, because the
            // service would throw inside it and roll the whole commit back with a
            // less specific message — and because a count check alone would let a
            // renumbered spreadsheet through.
            ImportTemplate::EvaluationChartUs => $this->candidates->assertMatchesCandidates($proposal, $payloads),
        };
    }

    private function assertWithinSanctionedStrength(DpcProposal $proposal, int $incoming): void
    {
        if (! (bool) config('dpc.vacancy.enforce_sanctioned_ceiling', true)) {
            return;
        }

        $sanctioned = $proposal->sanctioned_post_count;

        if ($sanctioned !== null && $incoming > $sanctioned) {
            throw new WorkflowException(
                'Working Officers cannot exceed the sanctioned strength.'
                ." This file carries {$incoming} officer(s) against a sanctioned strength of {$sanctioned}."
            );
        }
    }

    private function assertMatchesZone(DpcProposal $proposal, int $incoming): void
    {
        if (! (bool) config('dpc.vacancy.enforce_seniority_matches_zone', true)) {
            return;
        }

        $required = $this->vacancies->requiredSeniorityCount($proposal);

        if ($required !== null && $incoming !== $required) {
            throw new WorkflowException(
                $this->vacancies->seniorityCountMessage($required)
                ." This file carries {$incoming}."
            );
        }
    }

    private function assertProposalEditable(DpcProposal $proposal): void
    {
        if (! $proposal->status->isEditable()) {
            throw new WorkflowException('This proposal can no longer be edited — it is not in Draft/Returned status.');
        }
    }

    /**
     * Validate one mapped row against the shared per-row rules, merging in any
     * errors the parser already found (an unknown category code, a date it
     * could not read).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $parseErrors
     * @return array<string, list<string>>
     */
    private function validateRow(ImportTemplate $template, array $payload, array $parseErrors): array
    {
        $validator = Validator::make($payload, $template->rowRules());
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        if ($template->hasYearColumns()) {
            foreach (['cr_grades' => 'CR', 'loyalty_flags' => 'Loyalty', 'property_flags' => 'Immovable Property'] as $key => $label) {
                $yearRules = $key === 'cr_grades'
                    ? DpcRowRules::evaluationCrGrade()
                    : DpcRowRules::evaluationYearFlag();

                foreach ($payload[$key] ?? [] as $index => $yearRow) {
                    $yearValidator = Validator::make($yearRow, $yearRules);

                    if ($yearValidator->fails()) {
                        /** @var array<string, list<string>> $yearErrors */
                        $yearErrors = $yearValidator->errors()->toArray();
                        foreach ($yearErrors as $field => $messages) {
                            $errors["{$key}.{$index}.{$field}"] = array_map(
                                static fn (string $message): string => "{$label} column: {$message}",
                                $messages,
                            );
                        }
                    }
                }
            }

            $maxCrYears = (int) config('dpc.evaluation.max_cr_years');
            if (count($payload['cr_grades'] ?? []) > $maxCrYears) {
                $errors['cr_grades'] = ["The chart scores at most {$maxCrYears} CR years per officer (PPT S18)."];
            }
        }

        // Parse errors win on a shared key. When a category code is unknown the
        // parser leaves `reservation_category_id` unset, so the validator also
        // reports a generic "field is required" — but "Unknown category code
        // \"BOGUS\"" is the message that actually tells the officer what to fix
        // in their spreadsheet.
        return array_merge($errors, $parseErrors);
    }

    /**
     * Read the sheet and map every data row onto the service-layer array
     * shape. Returns parse-time errors per row rather than throwing, so one
     * bad cell rejects one row instead of the whole file.
     *
     * @return list<array{row_number: int, payload: array<string, mixed>, errors: array<string, list<string>>}>
     */
    private function parse(ImportTemplate $template, string $storedPath): array
    {
        $reader = new RawSheetImport;
        Excel::import($reader, $storedPath, config('dpc.imports.disk'));

        $sheet = $reader->rows;
        if ($sheet === []) {
            throw new WorkflowException('The file is empty.');
        }

        $headerRow = array_shift($sheet);
        $map = $this->buildHeaderMap($template, $headerRow);

        $categories = ReservationCategory::query()->pluck('id', 'code');
        $maxRows = (int) config('dpc.imports.max_rows');

        $parsed = [];
        $lineNumber = 1; // header consumed above

        foreach ($sheet as $rawRow) {
            $lineNumber++;

            if ($this->isBlank($rawRow)) {
                continue;
            }

            if (count($parsed) >= $maxRows) {
                throw new WorkflowException("This file has more than {$maxRows} data rows, which is the maximum a single import may carry.");
            }

            $parsed[] = $this->mapRow($template, $map, $rawRow, $lineNumber, $categories->all());
        }

        if ($parsed === []) {
            throw new WorkflowException('The file has a header row but no data rows.');
        }

        return $parsed;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array{fields: array<int, string>, years: array<int, array{type: string, year: int}>}
     */
    private function buildHeaderMap(ImportTemplate $template, array $headerRow): array
    {
        $columnMap = $template->columnMap();
        $fields = [];
        $years = [];

        foreach ($headerRow as $index => $heading) {
            $normalized = $this->normalizeHeading((string) $heading);

            if ($normalized === '') {
                continue;
            }

            if (isset($columnMap[$normalized])) {
                $fields[$index] = $columnMap[$normalized];

                continue;
            }

            if ($template->hasYearColumns()) {
                $year = $this->matchYearColumn($normalized);
                if ($year !== null) {
                    $years[$index] = $year;
                }
            }
        }

        if ($fields === []) {
            throw new WorkflowException('No recognisable column headers were found. Expected: '.implode(', ', $template->expectedHeaders()).'.');
        }

        return ['fields' => $fields, 'years' => $years];
    }

    /**
     * S18 per-year columns: "CR 2012", "LOYALTY 2013", "IMMOVABLE PROPERTY
     * 2013" and the like.
     *
     * @return array{type: string, year: int}|null
     */
    private function matchYearColumn(string $normalized): ?array
    {
        $patterns = [
            'cr_grades' => '/^(?:year_wise_)?cr_?(\d{4})$/',
            'loyalty_flags' => '/^loyalty_?(\d{4})$/',
            'property_flags' => '/^(?:immovable_)?(?:property|property_details|immovable_property_details)_?(\d{4})$/',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                return ['type' => $type, 'year' => (int) $matches[1]];
            }
        }

        return null;
    }

    /**
     * @param  array{fields: array<int, string>, years: array<int, array{type: string, year: int}>}  $map
     * @param  array<int, mixed>  $rawRow
     * @param  array<string, int>  $categories
     * @return array{row_number: int, payload: array<string, mixed>, errors: array<string, list<string>>}
     */
    private function mapRow(ImportTemplate $template, array $map, array $rawRow, int $lineNumber, array $categories): array
    {
        $payload = [];
        $errors = [];

        foreach ($map['fields'] as $index => $field) {
            $value = $this->cell($rawRow, $index);

            if ($field === 'reservation_category') {
                $code = strtoupper(trim((string) $value));
                if ($code === '') {
                    $errors['reservation_category_id'] = ['The Category column is required.'];

                    continue;
                }
                if (! isset($categories[$code])) {
                    $errors['reservation_category_id'] = ["Unknown category code \"{$code}\"."];

                    continue;
                }
                $payload['reservation_category_id'] = $categories[$code];

                continue;
            }

            if (str_starts_with($field, 'date_') || $field === 'dob' || $field === 'deputation_date') {
                if ($value === null || $value === '') {
                    continue;
                }
                $date = $this->parseDate($value);
                if ($date === null) {
                    $errors[$field] = ["Could not read \"{$value}\" as a date. Use DD-MM-YYYY."];

                    continue;
                }
                $payload[$field] = $date;

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $payload[$field] = is_string($value) ? trim($value) : $value;
        }

        if ($template->hasYearColumns()) {
            foreach ($map['years'] as $index => $meta) {
                $value = $this->cell($rawRow, $index);
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                $entry = ['year' => $meta['year']];

                if ($meta['type'] === 'cr_grades') {
                    $entry['grade'] = trim((string) $value);
                } else {
                    $flag = $this->parseFlag((string) $value);
                    if ($flag === null) {
                        $errors["{$meta['type']}.{$meta['year']}"] = ["Column for {$meta['year']}: expected Y or N, got \"{$value}\"."];

                        continue;
                    }
                    $entry['flag'] = $flag;
                }

                $payload[$meta['type']][] = $entry;
            }
        }

        return ['row_number' => $lineNumber, 'payload' => $payload, 'errors' => $errors];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function cell(array $row, int $index): mixed
    {
        return $row[$index] ?? null;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeading(string $heading): string
    {
        $normalized = strtolower(trim($heading));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim($normalized, '_');
    }

    /**
     * Spreadsheets hand dates over in three shapes: an Excel serial number, a
     * DateTime the reader already resolved, or free text the department typed.
     * The PPT's own tables use DD-MM-YYYY, so that is tried first — otherwise
     * "01-02-2012" would silently parse as 2 January.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'd-m-y', 'd/m/y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }

            // Round-tripping the value rejects near-misses Carbon would
            // otherwise coerce (e.g. "32-01-2012" rolling into February).
            if ($parsed !== null && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseFlag(string $value): ?bool
    {
        return match (strtoupper(trim($value))) {
            'Y', 'YES', '1', 'TRUE' => true,
            'N', 'NO', '0', 'FALSE' => false,
            default => null,
        };
    }
}
