<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CommitteeConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'committee_type_id' => ['required', Rule::exists('committee_types', 'id')],
            'min_members' => ['required', 'integer', 'min:1'],
            'max_members' => ['required', 'integer', 'gte:min_members'],
            'chairman_mandatory' => ['required', 'boolean'],
            'quorum' => ['required', 'integer', 'min:1', 'lte:max_members'],
            'voting_method' => ['required', 'string', 'max:60'],
            'majority_type' => ['required', 'string', 'max:60'],
            'tie_break_rule' => ['required', 'string', 'max:120'],
            'digital_signature_required' => ['required', 'boolean'],
            'remarks_required' => ['required', 'boolean'],
            'external_member_allowed' => ['required', 'boolean'],
            'secretary_required' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'chairman_mandatory' => $this->boolean('chairman_mandatory'),
            'digital_signature_required' => $this->boolean('digital_signature_required'),
            'remarks_required' => $this->boolean('remarks_required'),
            'external_member_allowed' => $this->boolean('external_member_allowed'),
            'secretary_required' => $this->boolean('secretary_required'),
        ]);
    }
}
