<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcEvaluationPropertyFlag;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\Concerns\DeclaresAMissingRecord;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Section D — one Immovable Property Return year (rule 4.1).
 *
 * A submitted return needs its submission date and the date of next increment
 * printed on it (page 4). One submitted after 31 January of the following year needs
 * the department's decision; an accepted late return needs the reason too.
 */
class SaveEmployeePropertyYearRequest extends FormRequest
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
            'submitted' => ['required', 'boolean'],
            'submitted_on' => ['required_if:submitted,1', 'nullable', 'date', 'before_or_equal:today'],
            'next_increment_date' => ['required_if:submitted,1', 'nullable', 'date'],
            'late_decision' => ['nullable', Rule::in([DpcEvaluationPropertyFlag::LATE_ACCEPTED, DpcEvaluationPropertyFlag::LATE_REJECTED])],
            'late_decision_remarks' => ['nullable', 'string', 'max:2000'],
            ...$this->missingRecordRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Not submitted in a year the promotion rule makes it mandatory: Missing,
            // with its reason and document (2026-11-05). An optional year asks nothing.
            $this->assertMissingJustification($validator, DpcEvaluationRecordGap::KIND_PROPERTY, $this->declaredMissing());

            if (! $this->boolean('submitted') || ! $this->filled('submitted_on') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $year = $this->integer('year');

            if (strtotime((string) $this->input('submitted_on')) <= DpcEvaluationPropertyFlag::dueDateFor($year)->getTimestamp()) {
                return;
            }

            if (! $this->filled('late_decision')) {
                $validator->errors()->add('late_decision', __('dpc.employee_details.property.late_decision_required', [
                    'date' => DpcEvaluationPropertyFlag::dueDateFor($year)->format('d-m-Y'),
                ]));
            } elseif ($this->input('late_decision') === DpcEvaluationPropertyFlag::LATE_ACCEPTED && ! $this->filled('late_decision_remarks')) {
                $validator->errors()->add('late_decision_remarks', __('dpc.employee_details.property.late_reason_required'));
            }
        });
    }

    /** A return not submitted in a year the promotion rule makes it mandatory. */
    public function declaredMissing(): bool
    {
        $row = $this->route('evaluationRow');

        return $this->has('submitted') && ! $this->boolean('submitted') && $row instanceof DpcEvaluationRow
            && in_array($this->integer('year'), app(EmployeeDetailsService::class)->rulePropertyYears($row), true);
    }
}
