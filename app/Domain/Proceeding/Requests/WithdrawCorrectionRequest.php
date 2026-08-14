<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing a correction (PPT S33) — M7 Phase 2.
 *
 * A withdrawal is itself a correction and carries its own reason. "Undone by
 * mistake" is exactly the entry that needs explaining later.
 */
class WithdrawCorrectionRequest extends FormRequest
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
            'reason.required' => 'A reason is required to withdraw a correction.',
            'reason.min' => 'Please state why this correction is being withdrawn in a little more detail.',
        ];
    }
}
