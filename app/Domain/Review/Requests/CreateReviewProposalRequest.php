<?php

declare(strict_types=1);

namespace App\Domain\Review\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Raising a Review DPC (PPT S37) — M8 Phase 1.
 *
 * S37 renders the reasons as **checkboxes**, so at least one is required and
 * several may be given. A Review without a stated ground is the one thing this
 * screen must not accept: the ground is what a tribunal reads to understand why
 * a concluded promotion was reopened.
 */
class CreateReviewProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->is_active
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'original_dpc_proposal_uuid' => ['required', 'string'],
            'proposal_date' => ['required', 'date'],
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => [Rule::exists('review_reasons', 'id')],
            'details' => ['nullable', 'array'],
            'details.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $original = DpcProposal::query()
                ->where('uuid', (string) $this->input('original_dpc_proposal_uuid'))
                ->first();

            if ($original === null) {
                $validator->errors()->add('original_dpc_proposal_uuid', 'That DPC could not be found.');

                return;
            }

            // A department reviews its own DPCs and no one else's. Checked
            // here as well as in the service, so a non-HTTP caller is bound.
            if ($original->department_id !== $this->user()?->department_id) {
                $validator->errors()->add(
                    'original_dpc_proposal_uuid',
                    'That DPC belongs to another department.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reasons.required' => 'Select at least one reason for the Review.',
            'reasons.min' => 'Select at least one reason for the Review.',
            'original_dpc_proposal_uuid.required' => 'Select the DPC being reviewed.',
        ];
    }
}
