<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Section C — one CR year, answered as page 3 of the Coder Instructions asks.
 *
 * One CR a year (decision of 2026-11-01): the authorities whose remarks it carries,
 * ticked, and the grade of the highest of them — a configured CR grade code.
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
        return [
            'year' => ['required', 'integer', 'min:'.(int) config('dpc.evaluation.min_year', 1950), 'max:2200'],
            'put_up' => ['required', 'boolean'],
            'has_grading' => ['required_if:put_up,1', 'nullable', 'boolean'],
            'authorities' => ['nullable', 'array'],
            'authorities.*' => ['string', Rule::in(EmployeeDetailsService::CR_AUTHORITIES)],
            'grade' => ['nullable', 'string', Rule::in((array) config('dpc.evaluation.cr_grades'))],
        ];
    }
}
