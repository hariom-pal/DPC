<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-scheduling (PPT S30, RE-SCHEDULING) — M6 Phase 3.
 *
 * A reason is required, as it is for re-allotment: a DPC date that moved
 * without a recorded explanation is exactly the sort of change a service
 * matter will ask about later.
 *
 * As with the original schedule, the date is **not** validated against the
 * default DPC date (architect Decision 4) and a past date is permitted.
 */
class RescheduleDpcRequest extends FormRequest
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
            'dpc_date' => ['required', 'date'],
            'dpc_time' => ['required', 'date_format:H:i'],
            'reschedule_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dpc_time.date_format' => 'Enter the time as HH:MM.',
            'reschedule_reason.required' => 'A reason is required when re-scheduling a DPC.',
        ];
    }
}
