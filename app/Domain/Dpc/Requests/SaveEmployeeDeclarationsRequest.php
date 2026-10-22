<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 2.3: is the officer on deputation without a sanctioned setup; rule 4.4: did
 * the officer refuse promotion to this post, and in which year.
 */
class SaveEmployeeDeclarationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $proposal = $this->route('dpcProposal');
        $year = $proposal instanceof DpcProposal ? (int) ($proposal->dpc_year ?: $proposal->proposal_date->year) : (int) now()->format('Y');

        return [
            'unsanctioned_deputation' => ['required', 'boolean'],
            'refused_promotion' => ['required', 'boolean'],
            'refused_promotion_year' => ['required_if:refused_promotion,1', 'nullable', 'integer', 'min:1950', 'max:'.$year],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['refused_promotion_year' => (string) __('dpc.employee_details.declarations.refused_year')];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('refused_promotion')) {
            $this->merge(['refused_promotion_year' => null]);
        }
    }
}
