<?php

declare(strict_types=1);

namespace App\Domain\Allotment\Requests;

use App\Enums\OfficerChangeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Allot, or reassign, a proposal's AO and AH (A5, 2026-10-26). The reason is
 * required by the service when a current allotment is being replaced.
 */
class AllotProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller asks DepartmentUserAuthority.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ao_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'ah_user_id' => ['required', 'integer', Rule::exists('users', 'id'), 'different:ao_user_id'],
            // Chosen from the list (MoM of 2026-10-29); required by the service on a reassignment.
            'reason' => ['nullable', Rule::in(OfficerChangeReason::values())],
            // The Government Order / assignment document, the declaration made
            // against it, and consent to the eSign (MoM of 2026-10-29).
            'order' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf', 'max:10240'],
            'declaration' => ['accepted'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ah_user_id.different' => (string) __('allotments.errors.same_officer'),
            'ao_user_id.required' => (string) __('allotments.picker.choose_ao'),
            'ah_user_id.required' => (string) __('allotments.picker.choose_ah'),
            'order.required' => (string) __('allotments.order.required'),
            'declaration.accepted' => (string) __('allotments.order.declaration_required'),
            'consent.accepted' => (string) __('esign.consent_required'),
        ];
    }
}
