<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Working strength as on 1 January, for one Vacancy Master position.
 *
 * Shape and type here; the rules that need the position (sanctioned ceiling,
 * correction reason on a second save, the split summing to the total) are the
 * service's, so they hold for any caller.
 */
class SaveWorkingStrengthRequest extends FormRequest
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
            'working_total' => ['required', 'integer', 'min:0', 'max:100000'],
            'working_promotion' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'working_direct' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'working_deputation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'exceeds_sanctioned_acknowledged' => ['nullable', 'boolean'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
