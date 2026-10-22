<?php

declare(strict_types=1);

namespace App\Domain\PreviousDpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\PreviousDpc\Models\PreviousDpcRecord;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Coder Instructions rule 1.1: the Commission's staff enter a DPC held offline.
 */
final class PreviousDpcRecordService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile|null>  $files  minutes, seniority_list, working_officers
     */
    public function record(array $data, array $files, User $actor): PreviousDpcRecord
    {
        if (($files['minutes'] ?? null) === null) {
            throw new WorkflowException(__('previous_dpc.minutes_required'));
        }

        if ((int) $data['eligible_count'] >= PreviousDpcRecord::UPLOADS_REQUIRED_FROM
            && (($files['seniority_list'] ?? null) === null || ($files['working_officers'] ?? null) === null)) {
            throw new WorkflowException(__('previous_dpc.uploads_required', ['count' => PreviousDpcRecord::UPLOADS_REQUIRED_FROM]));
        }

        return DB::transaction(function () use ($data, $files, $actor): PreviousDpcRecord {
            $stored = [];
            $disk = (string) config('dpc.documents.disk', 'local');

            foreach (PreviousDpcRecord::FILES as $name) {
                $file = $files[$name] ?? null;

                if ($file === null) {
                    continue;
                }

                $path = Storage::disk($disk)->putFile('private/previous-dpcs', $file);

                if ($path === false) {
                    throw new WorkflowException(__('previous_dpc.store_failed'));
                }

                $stored += [
                    $name.'_disk' => $disk,
                    $name.'_path' => $path,
                    $name.'_original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                    $name.'_sha256' => hash('sha256', (string) file_get_contents($file->getRealPath())),
                ];
            }

            $record = PreviousDpcRecord::query()->create([
                'department_id' => (int) $data['department_id'],
                'promotion_from_post_id' => $data['promotion_from_post_id'] ?? null,
                'promotion_to_post_id' => (int) $data['promotion_to_post_id'],
                'dpc_year' => (int) $data['dpc_year'],
                'held_on' => $data['held_on'],
                'reference' => trim((string) $data['reference']),
                'order_number' => $data['order_number'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'eligible_count' => (int) $data['eligible_count'],
                'promoted_officers' => self::parseOfficers((string) ($data['promoted_officers'] ?? '')),
                'remarks' => $data['remarks'] ?? null,
                'recorded_by' => $actor->id,
                ...$stored,
            ]);

            $this->timeline->record('previous_dpc.recorded', $record, __('previous_dpc.timeline', [
                'reference' => $record->reference,
                'year' => $record->dpc_year,
            ]), ['department_id' => $record->department_id, 'promotion_to_post_id' => $record->promotion_to_post_id], $actor);

            return $record;
        });
    }

    /**
     * One officer per line: "Employee ID, Name" (the Employee ID may be left out).
     *
     * @return list<array{employee_number: string|null, name: string}>
     */
    public static function parseOfficers(string $text): array
    {
        $officers = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $line, 2));
            $officers[] = count($parts) === 2 && $parts[1] !== ''
                ? ['employee_number' => $parts[0] === '' ? null : $parts[0], 'name' => $parts[1]]
                : ['employee_number' => null, 'name' => $parts[0]];
        }

        return $officers;
    }
}
