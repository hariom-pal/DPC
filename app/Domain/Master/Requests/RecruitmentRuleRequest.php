<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RecruitmentRuleRequest extends FormRequest
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
            'cadre_id' => ['required', Rule::exists('cadres', 'id')],
            'government_order_number' => ['required', 'string', 'max:255'],
            'direct_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'promotion_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
            'go_document' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:10240'],
            'feeders' => ['array'],
            'feeders.*.feeder_cadre_id' => ['required', Rule::exists('cadres', 'id')],
            'feeders.*.promotion_percentage' => ['required', 'numeric', 'between:0,100'],
            'feeders.*.min_experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            'feeders.*.min_qualification' => ['nullable', 'string', 'max:255'],
        ];
    }
}
