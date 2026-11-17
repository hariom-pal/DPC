<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Coder Instructions rule 2.2: whether a departmental enquiry, charge-sheet,
 * show-cause notice or suspension is pending against the officer, and if so which
 * (from the Sealed Cover Reason master), since when, and any remarks. The order is
 * uploaded as a document of kind `vigilance_order`.
 */
class SaveEmployeeVigilanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('updateEmployeeSection', [$proposal, 'vigilance']) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 2026-11-14: a case ongoing and a punishment in effect in the DPC's period,
            // each answered with its document, Yes or No.
            'enquiry_ongoing' => ['required', 'boolean'],
            'punishment_effective' => ['required', 'boolean'],
            'enquiry_document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'punishment_document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'vigilance_verified' => ['nullable', 'boolean'],
            'vigilance_pending' => ['required', 'boolean'],
            'sealed_cover_reason_id' => [
                'required_if:vigilance_pending,1', 'nullable', 'integer',
                Rule::exists('sealed_cover_reasons', 'id')->where('status', MasterStatus::Active->value)->whereNull('deleted_at'),
            ],
            'vigilance_date' => ['required_if:vigilance_pending,1', 'nullable', 'date', 'before_or_equal:today'],
            'vigilance_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enquiry_ongoing' => (string) __('dpc.employee_details.vigilance.enquiry_question_short'),
            'punishment_effective' => (string) __('dpc.employee_details.vigilance.punishment_question_short'),
            'sealed_cover_reason_id' => (string) __('dpc.employee_details.vigilance.reason'),
            'vigilance_date' => (string) __('dpc.employee_details.vigilance.date'),
            'vigilance_remarks' => (string) __('ui.remarks'),
        ];
    }

    /** A Yes answer's document is on file already or comes now, verified against its original (rule 4.4); a No asks none (2026-11-16). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $row = $this->route('evaluationRow');
            $onFile = $row instanceof DpcEvaluationRow ? $row->currentDocuments()->pluck('kind')->map(fn ($kind) => $kind instanceof EvaluationDocumentKind ? $kind->value : (string) $kind)->all() : [];

            foreach (['enquiry_ongoing' => ['enquiry_document', EvaluationDocumentKind::EnquiryDocument], 'punishment_effective' => ['punishment_document', EvaluationDocumentKind::PunishmentDocument]] as $answer => [$field, $kind]) {
                if ($this->boolean($answer) && ! $this->hasFile($field) && ! in_array($kind->value, $onFile, true)) {
                    $validator->errors()->add($field, (string) __('dpc.employee_details.vigilance.document_required'));
                }
            }

            if ((($this->boolean('enquiry_ongoing') && $this->hasFile('enquiry_document')) || ($this->boolean('punishment_effective') && $this->hasFile('punishment_document'))) && ! $this->boolean('vigilance_verified')) {
                $validator->errors()->add('vigilance_verified', (string) __('dpc.employee_details.verification.ao_required'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Nothing pending: the details are cleared, not kept.
        if (! $this->boolean('vigilance_pending')) {
            $this->merge(['sealed_cover_reason_id' => null, 'vigilance_date' => null, 'vigilance_remarks' => null]);
        }
    }
}
