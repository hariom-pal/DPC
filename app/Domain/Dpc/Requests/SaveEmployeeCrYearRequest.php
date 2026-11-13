<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\Concerns\DeclaresAMissingRecord;
use App\Domain\Dpc\Services\EmployeeDetailsService;
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
            ...$this->missingRecordRules(),
        ];
    }

    /** A CR not put up is Missing: its reason and document, here (2026-11-05). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertMissingJustification($validator, DpcEvaluationRecordGap::KIND_CR, $this->has('put_up') && ! $this->boolean('put_up'));
        });
    }
}
