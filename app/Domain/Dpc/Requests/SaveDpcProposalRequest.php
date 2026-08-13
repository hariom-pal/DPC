<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Master\Models\DpcType;
use App\Enums\MasterStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Post selection (PPT S14) — M4 Phase 1. `dpc_type_id` accepts any active
 * DPC type shown on the S14 mockup (Fresh/Review/Circulation — never hide
 * PPT content), but only `fresh` is functionally supported until Review DPC
 * (M8) and Circulation DPC (M9) are built; anything else is rejected with a
 * clear message rather than a generic validation error.
 */
class SaveDpcProposalRequest extends FormRequest
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
        $departmentId = $this->user()?->department_id;

        return [
            'dpc_type_id' => ['required', Rule::exists('dpc_types', 'id')],
            'post_to_cadre_id' => [
                'required',
                Rule::exists('cadres', 'id')->where(fn ($query) => $query
                    ->where('department_id', $departmentId)
                    ->where('status', MasterStatus::Active->value)),
            ],
            'post_from_cadre_id' => [
                'required',
                'different:post_to_cadre_id',
                Rule::exists('cadres', 'id')->where(fn ($query) => $query
                    ->where('department_id', $departmentId)
                    ->where('status', MasterStatus::Active->value)),
            ],
            'legal_stay' => ['nullable', 'boolean'],
            'proposal_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeId = $this->input('dpc_type_id');

            if ($typeId === null) {
                return;
            }

            $type = DpcType::query()->find($typeId);

            if ($type !== null && $type->code !== 'fresh') {
                $validator->errors()->add(
                    'dpc_type_id',
                    'Review DPC and Circulation DPC are not yet available — planned for later milestones (M8/M9). Only Fresh DPC can be created today.',
                );
            }
        });
    }
}
