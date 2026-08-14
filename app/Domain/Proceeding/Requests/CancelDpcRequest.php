<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CANCEL DPC (PPT S33) — M7 Phase 3.
 *
 * Cancellation is terminal and cannot be undone, so the reason is the only
 * record of why a scheduled DPC produced no result.
 */
class CancelDpcRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proceeding = $this->route('proceeding');

        return $proceeding instanceof DpcProceeding
            && ($this->user()?->can('act', $proceeding) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required', 'string',
                'min:'.(int) config('dpc.proceeding.correction_reason_min', 10),
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
            'reason.required' => 'A reason is required to cancel a DPC.',
            'reason.min' => 'Please state why this DPC is being cancelled in a little more detail.',
        ];
    }
}
