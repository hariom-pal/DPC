<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sanctioned strength for one Vacancy Year of one Vacancy Master position.
 *
 * Shape and type here; the rules that need the position (a correction needs a reason)
 * are the service's, so they hold for any caller.
 */
class SaveSanctionedStrengthRequest extends FormRequest
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
            'sanctioned_total' => ['required', 'integer', 'min:0', 'max:100000'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
