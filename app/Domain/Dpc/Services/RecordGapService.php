<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Record why a mandatory CR or Loyalty Certificate is missing (change register of
 * 2026-08-27, items 14, 15, 16, 17 and 19).
 *
 * > *AO shall upload Reason and Supporting PDF. Candidate shall continue in the DPC.
 * > Candidate Status = CIR.*
 *
 * ### Two things this service is careful about
 *
 * **The reason and the document are saved together or not at all.** Item 19 makes
 * both mandatory, and {@see OfficerRecord} only
 * counts a gap as justified when the file is actually attached. So a half-saved gap
 * would be worse than no gap: it looks answered on the screen and reads as
 * unanswered to the engine. The transaction and the `$file === null` refusal below
 * are what keep those two readings the same.
 *
 * **The hash is taken from the bytes that arrived**, not re-read from the stored
 * copy — a hash computed from what was written would certify the copy against
 * itself and prove nothing about what the department sent.
 *
 * ### Replacing rather than editing
 *
 * A gap is unique per (row, kind, year), and re-recording one **replaces** it. That
 * is deliberate: an officer correcting a wrongly-chosen reason should not have to
 * delete first, and the audit trail keeps the previous version — `Auditable` records
 * the change, so what the officer first said is recoverable without keeping a
 * superseded row that the engine would then have to learn to ignore.
 */
class RecordGapService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * Save one gap, with its reason and its evidence.
     *
     * @throws RuntimeException when the file is absent or cannot be stored
     */
    public function record(
        DpcEvaluationRow $row,
        string $kind,
        int $year,
        RecordReason $reason,
        ?string $remarks,
        ?UploadedFile $file,
        User $actor,
    ): DpcEvaluationRecordGap {
        if (! in_array($kind, DpcEvaluationRecordGap::kinds(), true)) {
            throw new RuntimeException("Unknown record kind [{$kind}].");
        }

        /*
         | No document, no justification.
         |
         | Enforced here as well as in the form request, because this is the last
         | point before the row exists: a gap saved without its PDF would show on the
         | screen as accounted for and reach the engine as unaccounted for, and the
         | officer would have no way to tell which reading the Commission acted on.
         */
        if ($file === null) {
            throw new RuntimeException(
                'A missing record must be justified with both a reason and a supporting document.',
            );
        }

        if ($reason->requires_remarks && trim((string) $remarks) === '') {
            throw new RuntimeException("The reason [{$reason->code}] requires remarks.");
        }

        return DB::transaction(function () use ($row, $kind, $year, $reason, $remarks, $file, $actor): DpcEvaluationRecordGap {
            $disk = (string) config('dpc.documents.disk', 'local');
            $contents = (string) file_get_contents($file->getRealPath());

            $path = Storage::disk($disk)->putFile(
                sprintf('private/record-gaps/%d', $row->dpc_proposal_id),
                $file,
            );

            if ($path === false) {
                throw new RuntimeException('The supporting document could not be stored.');
            }

            $gap = DpcEvaluationRecordGap::query()->updateOrCreate(
                [
                    'dpc_evaluation_row_id' => $row->id,
                    'kind' => $kind,
                    'year' => $year,
                ],
                [
                    'record_reason_id' => $reason->id,
                    'remarks' => $remarks === null || trim($remarks) === '' ? null : trim($remarks),
                    'disk' => $disk,
                    'path' => $path,

                    // Sanitised, and only ever displayed. A filename from a browser is
                    // user input and never used to build the stored path.
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),

                    'size_bytes' => $file->getSize() ?: 0,
                    'sha256' => hash('sha256', $contents),
                    'recorded_by' => $actor->id,
                ],
            );

            /*
             | Recorded on the timeline, because this is a decision and not data entry.
             |
             | A gap is what moves a candidate to CIR, so the exercise's own history
             | has to show when the justification was accepted and by whom — otherwise
             | a held promotion has no visible cause.
             */
            $this->timeline->record(
                'dpc_proposal.record_gap_recorded',
                $row->proposal,
                sprintf(
                    '%s for %d recorded as unavailable for officer %d (%s) — %s. Candidate continues in the DPC '
                    .'with status CIR.',
                    $kind === DpcEvaluationRecordGap::KIND_CR ? 'CR' : 'Loyalty Certificate',
                    $year,
                    $row->sn,
                    $row->name,
                    $reason->name_en,
                ),
                [
                    'kind' => $kind,
                    'year' => $year,
                    'sn' => $row->sn,
                    'reason_code' => $reason->code,
                    'reason_name_en' => $reason->name_en,
                    'remarks' => $gap->remarks,
                    'document_name' => $gap->original_name,
                    'document_sha256' => $gap->sha256,
                ],
                $actor,
            );

            return $gap;
        });
    }

    /**
     * Withdraw a gap.
     *
     * Deleting it is right rather than flagging it: a gap recorded against the wrong
     * year is not history, it is a mistake, and leaving it would hold a promotion for
     * a record that was never missing. The audit trail keeps what was withdrawn.
     */
    public function withdraw(DpcEvaluationRecordGap $gap, User $actor): void
    {
        DB::transaction(function () use ($gap, $actor): void {
            $this->timeline->record(
                'dpc_proposal.record_gap_withdrawn',
                $gap->evaluationRow->proposal,
                sprintf(
                    '%s for %d no longer recorded as unavailable for officer %d (%s).',
                    $gap->kind === DpcEvaluationRecordGap::KIND_CR ? 'CR' : 'Loyalty Certificate',
                    $gap->year,
                    $gap->evaluationRow->sn,
                    $gap->evaluationRow->name,
                ),
                [
                    'kind' => $gap->kind,
                    'year' => $gap->year,
                    'sn' => $gap->evaluationRow->sn,
                    'document_sha256' => $gap->sha256,
                ],
                $actor,
            );

            $gap->delete();
        });
    }
}
