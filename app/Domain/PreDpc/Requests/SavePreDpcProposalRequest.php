<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Requests;

use App\Enums\ServiceClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SavePreDpcProposalRequest extends FormRequest
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
            'government_order_number' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'posts' => ['array'],
            'posts.*.post_name' => ['required', 'string', 'max:255'],
            'posts.*.class_level' => ['required', new Enum(ServiceClass::class)],
            'posts.*.pay_scale_id' => ['nullable', Rule::exists('pay_scales', 'id')],
            'posts.*.direct_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'posts.*.promotion_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'posts.*.feeders' => ['array'],
            'posts.*.feeders.*.feeder_name' => ['required', 'string', 'max:255'],
            'posts.*.feeders.*.feeder_cadre_id' => ['nullable', Rule::exists('cadres', 'id')],
            'posts.*.feeders.*.promotion_percentage' => ['required', 'numeric', 'between:0,100'],
            'posts.*.feeders.*.min_experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            'posts.*.feeders.*.min_qualification' => ['nullable', 'string', 'max:255'],
        ];
    }
}
