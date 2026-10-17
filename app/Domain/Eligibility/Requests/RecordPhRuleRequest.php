<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Requests;

use App\Domain\Eligibility\Models\PhReservationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new version of the PH reservation in promotion (rule 2.1).
 */
class RecordPhRuleRequest extends FormRequest
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
            'effective_from_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'rounding' => ['required', Rule::in(PhReservationRule::ROUNDINGS)],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'effective_from_year' => __('dpcrules.effective_from_year'),
            'percentage' => __('dpcrules.ph.percentage'),
            'rounding' => __('dpcrules.ph.rounding'),
            'order_number' => __('dpcrules.order_number'),
            'order_date' => __('dpcrules.order_date'),
            'document' => __('dpcrules.document'),
            'remarks' => __('dpcrules.remarks'),
        ];
    }
}
