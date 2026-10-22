<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Master\Models\RecordReason;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording why one mandatory record is missing (change register of 2026-08-27,
 * items 14 to 19).
 *
 * ### What the register makes mandatory, and where each is enforced
 *
 * | Requirement | Item | Enforced by |
 * |---|---|---|
 * | A reason from the master | 16 | `record_reason_id` + `Rule::exists` on the selectable scope |
 * | A supporting PDF | 19 | `document` — `required`, and `mimes:pdf` |
 * | Remarks when the reason says so | 16 | {@see withValidator()} |
 * | The year is one the chart carries | 15 | {@see withValidator()} |
 *
 * **Remarks are conditional on the reason's own flag, not on the code `other`.** The
 * master carries `requires_remarks` as a column precisely so an Administrator can
 * mark *Court Case* as needing the case number without a deployment — hardcoding
 * `other` here would quietly ignore that.
 */
class RecordGapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(DpcEvaluationRecordGap::kinds())],
            'year' => ['required', 'integer', 'min:1950', 'max:2200'],

            /*
             | Selectable, not merely existing.
             |
             | A reason the Commission has withdrawn stays attached to the gaps that
             | already cite it — withdrawing it does not rewrite why a record was
             | missing two years ago — but it must not be choosable on a new one.
             */
            'record_reason_id' => [
                'required',
                Rule::exists('record_reasons', 'id')->where(
                    fn ($query) => $query->where('status', 'active'),
                ),
            ],

            'remarks' => ['nullable', 'string', 'max:2000'],

            /*
             | The document is required, full stop (item 19).
             |
             | Not `sometimes`, and not required-only-on-create: re-recording a gap
             | replaces it, and a replacement that dropped the evidence would turn a
             | justified absence into an unjustified one without saying so.
             */
            'document' => [
                'required',
                'file',
                'mimes:pdf',
                'max:'.(int) config('dpc.documents.max_kb', 10240),
            ],

            // Rule 3.2: the second document a reason may need (a suspension's reinstatement order).
            'second_document' => [
                Rule::requiredIf(fn (): bool => RecordReason::query()->find($this->input('record_reason_id'))?->requiresSecondDocument() === true),
                'nullable',
                'file',
                'mimes:pdf',
                'max:'.(int) config('dpc.documents.max_kb', 10240),
            ],
        ];
    }

    /**
     * The two checks that need more than one field to make.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertRemarksWhereTheReasonDemandsThem($validator);
            $this->assertTheYearIsOnTheChart($validator);
        });
    }

    /**
     * Item 16: *"If 'Other' is selected, Remarks become mandatory."*
     */
    private function assertRemarksWhereTheReasonDemandsThem(Validator $validator): void
    {
        $reason = RecordReason::query()->find($this->input('record_reason_id'));

        if ($reason === null || ! $reason->requires_remarks) {
            return;
        }

        if (trim((string) $this->input('remarks')) === '') {
            $validator->errors()->add('remarks', __('dpc.record_gaps.remarks_required', [
                'reason' => $reason->label(),
            ]));
        }
    }

    /**
     * A gap has to be *about* a year the chart knows.
     *
     * A CR gap recorded against a year the officer never had on their chart would
     * hold a promotion for a record that was never expected — and a Loyalty gap is
     * tied to a CR year by item 15, so for that kind the year must actually carry a
     * CR.
     */
    private function assertTheYearIsOnTheChart(Validator $validator): void
    {
        $row = $this->route('evaluationRow');
        $year = (int) $this->input('year');

        if ($row === null || $year === 0) {
            return;
        }

        if ($this->input('kind') !== DpcEvaluationRecordGap::KIND_LOYALTY) {
            return;
        }

        $hasCr = $row->crGrades()->where('year', $year)->exists();

        if (! $hasCr) {
            $validator->errors()->add('year', __('dpc.record_gaps.loyalty_needs_a_cr_year', ['year' => $year]));
        }
    }
}
