<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Section E — the PH questionnaire (rule 2.1). Each question is required only when
 * the previous answer leads to it; the service clears the answers a branch does not
 * reach.
 */
class SaveEmployeePhRequest extends FormRequest
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
            'is_ph' => ['required', 'boolean'],
            'ph_previously_availed' => ['required_if:is_ph,1', 'nullable', 'boolean'],
            'ph_claims_reservation' => ['required_if:ph_previously_availed,0', 'nullable', 'boolean'],
            'ph_ineligibility_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * `required_if:ph_previously_availed,0` would also fire for a non-PH officer whose
     * hidden radio still posts 0, so the later answers are dropped when the branch
     * stops earlier.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_ph')) {
            $this->merge(['ph_previously_availed' => null, 'ph_claims_reservation' => null]);
        } elseif ($this->boolean('ph_previously_availed')) {
            $this->merge(['ph_claims_reservation' => null]);
        }
    }
}
