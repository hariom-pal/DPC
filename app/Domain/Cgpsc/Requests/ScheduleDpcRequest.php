<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The allottee's scheduling form (PPT S30) — M6 Phase 2.
 *
 * **The date is deliberately not validated against the allotment's default
 * DPC date** (architect Decision 4): the default is informational until CGPSC
 * specifies a rule, and enforcing one here would be inventing a business rule.
 *
 * A past date is likewise permitted. CGPSC may legitimately record a DPC that
 * has already taken place, and refusing it would be another invented rule.
 */
class ScheduleDpcRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dpc_date.required' => 'Choose the date the DPC will be held.',
            'dpc_time.required' => 'Choose the time the DPC will be held.',
            'dpc_time.date_format' => 'Enter the time as HH:MM.',
        ];
    }
}
