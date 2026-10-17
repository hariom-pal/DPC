<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Services\PhReservationPolicy;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ReservationPolicyRequest extends FormRequest
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
            'reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],
            'percentage' => ['required', 'numeric', 'between:0,100'],
            // A quota with a decimal part: `floor` drops it (even .9, rule 2.1), `ceil` rounds up.
            'rounding' => ['required', Rule::in(PhReservationPolicy::ROUNDINGS)],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * Floor unless stated: rule 2.1 drops the decimal part.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('rounding')) {
            $this->merge(['rounding' => 'floor']);
        }
    }
}
