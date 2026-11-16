<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\Concerns\DeclaresAMissingRecord;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Master\Models\IntegrityOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Section C — one CR year, answered as page 3 of the Coder Instructions asks.
 *
 * Up to four CRs a year (MoM of 12 November 2026): how many, and for each the
 * authorities whose remarks it carries and its grade, if any; and the year's NRC
 * where a CR lacks an authority's remarks. The single-CR fields of the earlier form
 * (`authorities`, `grade`) are still read.
 */
class SaveEmployeeCrYearRequest extends FormRequest
{
    use DeclaresAMissingRecord;

    /**
     * Authorised before validation, so a user who may not edit the proposal is
     * refused outright instead of being shown validation messages about it.
     */
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:'.(int) config('dpc.evaluation.min_year', 1950), 'max:2200'],
            'put_up' => ['required', 'boolean'],
            // A CR put up with a grading counts; one without a grading is uploaded and
            // does not count (2026-11-16, restored).
            'has_grading' => ['required_if:put_up,1', 'nullable', 'boolean'],
            'authorities' => ['nullable', 'array'],
            'authorities.*' => ['string', Rule::in(EmployeeDetailsService::CR_AUTHORITIES)],
            'grade' => ['nullable', 'string', Rule::in((array) config('dpc.evaluation.cr_grades'))],
            'cr_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('dpc.employee_details.max_crs_per_year', 4)],
            'reports' => ['nullable', 'array', 'max:'.(int) config('dpc.employee_details.max_crs_per_year', 4)],
            'reports.*.authorities' => ['nullable', 'array'],
            'reports.*.authorities.*' => ['string', Rule::in(EmployeeDetailsService::CR_AUTHORITIES)],
            'reports.*.grade' => ['nullable', 'string', Rule::in((array) config('dpc.evaluation.cr_grades'))],
            'nrc' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            // The CR itself, uploaded with its grading (2026-11-14): verified against its
            // original (rule 4.4) and with the no-duplicate undertaking, as the separate upload asks.
            'cr_document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'cr_verified' => ['nullable', 'boolean'],
            'cr_undertaking' => ['nullable', 'boolean'],
            // Integrity (सत्यनिष्ठा) of the year, beside the grade (2026-11-14).
            'integrity_option_id' => ['nullable', 'integer', Rule::exists('integrity_options', 'id')->where('status', 'active')->whereNull('deleted_at')],
            // 2026-11-16: the Final Departmental View Regarding Integrity, and its document.
            'integrity_departmental_view' => ['nullable', Rule::in([DpcEvaluationCrGrade::VIEW_CERTIFIED, DpcEvaluationCrGrade::VIEW_NOT_CERTIFIED])],
            'integrity_document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            ...$this->missingRecordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cr_document.mimes' => __('dpc.employee_details.documents.pdf_only'),
            'cr_document.max' => __('dpc.employee_details.documents.too_large', ['kb' => (int) config('dpc.documents.max_kb', 10240)]),
        ];
    }

    /** A CR not put up is Missing: its reason and document, here (2026-11-05). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertMissingJustification($validator, DpcEvaluationRecordGap::KIND_CR, $this->has('put_up') && ! $this->boolean('put_up'));

            $this->assertDepartmentalView($validator);

            if ($this->hasFile('cr_document') && $this->boolean('put_up')) {
                if (! $this->boolean('cr_verified')) {
                    $validator->errors()->add('cr_verified', (string) __('dpc.employee_details.verification.ao_required'));
                }

                if (! $this->boolean('cr_undertaking')) {
                    $validator->errors()->add('cr_undertaking', (string) __('dpc.employee_details.documents.undertaking_required'));
                }
            }
        });
    }

    /**
     * An Integrity answer that asks for the Final Departmental View needs the view
     * and its document (2026-11-16) — the document unless the year already has one.
     */
    private function assertDepartmentalView(Validator $validator): void
    {
        if (! $this->boolean('put_up') || ! $this->filled('integrity_option_id')) {
            return;
        }

        $option = IntegrityOption::query()->find($this->input('integrity_option_id'));

        if ($option === null || ! $option->needs_departmental_view) {
            return;
        }

        if (! $this->filled('integrity_departmental_view')) {
            $validator->errors()->add('integrity_departmental_view', (string) __('dpc.employee_details.cr.integrity_view_required'));
        }

        $row = $this->route('evaluationRow');
        $onFile = $row instanceof DpcEvaluationRow && $row->currentDocuments()
            ->where('kind', EvaluationDocumentKind::IntegrityDecision->value)
            ->where('year', $this->integer('year'))
            ->exists();

        if (! $this->hasFile('integrity_document') && ! $onFile) {
            $validator->errors()->add('integrity_document', (string) __('dpc.employee_details.cr.integrity_document_required'));
        }
    }
}
