<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Section C — one CR year, answered as page 3 of the Coder Instructions asks.
 *
 * Grades are the configured CR grade codes; at most four reports a year. A report
 * block left entirely blank is ignored, so the form can always offer four.
 */
class SaveEmployeeCrYearRequest extends FormRequest
{
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
        $grades = Rule::in((array) config('dpc.evaluation.cr_grades'));
        $max = (int) config('dpc.employee_details.max_crs_per_year', 4);

        $rules = [
            'year' => ['required', 'integer', 'min:'.(int) config('dpc.evaluation.min_year', 1950), 'max:2200'],
            'put_up' => ['required', 'boolean'],
            'has_grading' => ['required_if:put_up,1', 'nullable', 'boolean'],
            'reports' => ['nullable', 'array', 'max:'.$max],
        ];

        foreach (['reporter', 'reviewer', 'accepting'] as $role) {
            $rules["reports.*.{$role}_designation"] = ['nullable', 'string', 'max:120'];
            $rules["reports.*.{$role}_grade"] = ['nullable', 'string', $grades];
        }

        return $rules;
    }
}
