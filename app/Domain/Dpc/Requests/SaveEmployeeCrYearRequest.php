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

        return $proposal instanceof DpcProposal && ($this->user()?->can('updateEmployeeSection', [$proposal, 'cr', $this->route('evaluationRow') instanceof DpcEvaluationRow ? $this->route('evaluationRow') : null]) ?? false);
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
            // The DPC Chairman's reason for changing a grading at the sitting (2026-11-22).
            'chairman_reason' => ['nullable', 'string', 'max:1000'],
            'cr_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('dpc.employee_details.max_crs_per_year', 4)],
            'reports' => ['nullable', 'array', 'max:'.(int) config('dpc.employee_details.max_crs_per_year', 4)],
            'reports.*.authorities' => ['nullable', 'array'],
            'reports.*.authorities.*' => ['string', Rule::in(EmployeeDetailsService::CR_AUTHORITIES)],
            'reports.*.grade' => ['nullable', 'string', Rule::in((array) config('dpc.evaluation.cr_grades'))],
            // Posted in a tribal area for this CR (2026-11-18).
            'reports.*.tribal_area' => ['nullable', 'boolean'],
            'nrc' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            // The CR itself, uploaded with its grading (2026-11-14): verified against its
            // original (rule 4.4) and with the no-duplicate undertaking, as the separate upload asks.
            'cr_document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            // One upload for each CR of a graded year (2026-11-16), keyed by its position.
            'cr_documents' => ['nullable', 'array', 'max:'.(int) config('dpc.employee_details.max_crs_per_year', 4)],
            'cr_documents.*' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'cr_verified' => ['nullable', 'boolean'],
            'cr_undertaking' => ['nullable', 'boolean'],
            // Integrity (सत्यनिष्ठा) of the year, beside the grade (2026-11-14).
            // Posted in a tribal area this year (2026-11-16).
            'tribal_area' => ['nullable', 'boolean'],
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
            'cr_documents.*.mimes' => __('dpc.employee_details.documents.pdf_only'),
            'cr_documents.*.max' => __('dpc.employee_details.documents.too_large', ['kb' => (int) config('dpc.documents.max_kb', 10240)]),
        ];
    }

    /** A CR not put up is Missing: its reason and document, here (2026-11-05). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertMissingJustification($validator, DpcEvaluationRecordGap::KIND_CR, $this->has('put_up') && ! $this->boolean('put_up'));

            $this->assertDepartmentalView($validator);

            $this->assertChairmanReason($validator);

            if (($this->hasFile('cr_document') || array_filter((array) $this->file('cr_documents', [])) !== []) && $this->boolean('put_up')) {
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
     * The DPC Chairman may change any candidate's grading at the sitting, and is asked
     * why (2026-11-22): a CR year whose put-up, grading or grades would differ from
     * what is recorded needs the reason. The calculated total follows the new grading.
     */
    private function assertChairmanReason(Validator $validator): void
    {
        $proposal = $this->route('dpcProposal');
        $row = $this->route('evaluationRow');
        if (! $proposal instanceof DpcProposal || ! $row instanceof DpcEvaluationRow || ! ($this->user()?->can('editAtSitting', $proposal) ?? false)) {
            return;
        }

        if (trim((string) $this->input('chairman_reason', '')) !== '' || ! $this->changesGrading($row)) {
            return;
        }

        $validator->errors()->add('chairman_reason', (string) __('dpc.employee_details.cr.chairman_reason_required'));
    }

    /** Whether what is posted would change the year's put-up, grading or grades. */
    private function changesGrading(DpcEvaluationRow $row): bool
    {
        $current = $row->crGrades()->where('year', $this->integer('year'))->first();
        if ($current === null) {
            return true;
        }

        $putUp = $this->boolean('put_up');
        if ($current->put_up !== $putUp) {
            return true;
        }
        if (! $putUp) {
            return false;
        }
        if ((bool) $current->has_grading !== $this->boolean('has_grading')) {
            return true;
        }

        return $this->postedGrades() !== self::gradesOf((array) ($current->reports ?? []), $current->grade);
    }

    /** @return list<string> */
    private function postedGrades(): array
    {
        $reports = $this->input('reports');
        if (is_array($reports)) {
            $count = max(1, $this->integer('cr_count', count($reports)));

            return self::gradesOf(array_slice(array_values($reports), 0, $count), null);
        }

        return self::gradesOf([], $this->input('grade'));
    }

    /**
     * @param  array<int|string, mixed>  $reports
     * @return list<string>
     */
    private static function gradesOf(array $reports, mixed $single): array
    {
        $grades = [];
        foreach ($reports as $report) {
            $grade = is_array($report) ? trim((string) ($report['grade'] ?? '')) : '';
            if ($grade !== '') {
                $grades[] = $grade;
            }
        }
        if ($grades === [] && trim((string) $single) !== '') {
            $grades[] = trim((string) $single);
        }

        return $grades;
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
