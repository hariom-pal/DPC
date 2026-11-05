<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests\Concerns;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Master\Models\RecordReason;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A mandatory CR or Property Return marked Missing on its own form (2026-11-05):
 * the reason and the supporting document are asked there and then, and recorded as
 * the existing missing-record justification (rule 3.2). A year already justified is
 * not asked again.
 */
trait DeclaresAMissingRecord
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function missingRecordRules(): array
    {
        $pdf = ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)];

        return [
            'missing_reason_id' => ['nullable', 'integer', Rule::exists('record_reasons', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'missing_remarks' => ['nullable', 'string', 'max:2000'],
            'missing_document' => $pdf,
            'missing_second_document' => $pdf,
        ];
    }

    /** Whether the record is declared missing on this form and needs its justification. */
    protected function needsMissingJustification(string $kind, bool $declaredMissing): bool
    {
        $row = $this->route('evaluationRow');

        if (! $declaredMissing || ! $row instanceof DpcEvaluationRow) {
            return false;
        }

        return ! $row->recordGaps()->where('kind', $kind)->where('year', $this->integer('year'))->get()
            ->contains(fn (DpcEvaluationRecordGap $gap): bool => $gap->isJustified());
    }

    protected function assertMissingJustification(Validator $validator, string $kind, bool $declaredMissing): void
    {
        if (! $this->needsMissingJustification($kind, $declaredMissing)) {
            return;
        }

        $reason = RecordReason::query()->find($this->input('missing_reason_id'));

        if ($reason === null) {
            $validator->errors()->add('missing_reason_id', __('dpc.employee_details.missing_record.reason_required'));
        } elseif ($reason->requires_remarks && trim((string) $this->input('missing_remarks')) === '') {
            $validator->errors()->add('missing_remarks', __('dpc.record_gaps.remarks_required', ['reason' => $reason->label()]));
        }

        if (! $this->hasFile('missing_document')) {
            $validator->errors()->add('missing_document', __('dpc.employee_details.missing_record.document_required'));
        }

        if ($reason?->requiresSecondDocument() === true && ! $this->hasFile('missing_second_document')) {
            $validator->errors()->add('missing_second_document', __('dpc.employee_details.missing_record.second_document_required', ['document' => $reason->second_document_label_en]));
        }
    }
}
