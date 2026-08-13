<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chairman's allotment / re-allotment form (PPT S28) — M6 Phase 1.
 *
 * `default_dpc_date` is **optional**: it is manual Chairman input (architect
 * Decision 2), and requiring it would itself be an invented business rule. The
 * PPT shows the column populated but states no rule that it must be.
 *
 * `allotted_to` is validated against users actually holding a CGPSC role
 * rather than against a fixed list of five — the M1–M5 columns on S28 are a
 * layout, not a limit (architect Decision 1).
 */
class AllotDpcRequest extends FormRequest
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
            'allotted_to' => [
                'required',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'default_dpc_date' => ['nullable', 'date'],
            'reallotment_reason' => [
                Rule::requiredIf(fn (): bool => $this->routeIs('cgpsc.allotments.reallot')),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allotted_to.required' => 'Choose the Member (or Chairman) this DPC is allotted to.',
            'reallotment_reason.required' => 'A reason is required when re-allotting a DPC.',
        ];
    }

    /**
     * Roles a DPC may be allotted to. Resolved from the role, not hardcoded.
     *
     * @return list<string>
     */
    public static function allottableRoles(): array
    {
        return [Role::Member->value, Role::Chairman->value];
    }
}
