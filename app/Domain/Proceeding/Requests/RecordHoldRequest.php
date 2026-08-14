<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Commission holds a promotion of its own motion (PPT S45) — M7 Phase 4.
 *
 * A hold reserves a promotion, so the reason has to be stated: "held" without
 * a stated cause is not something a tribunal can weigh, and it is the officer
 * whose promotion is deferred who is entitled to know why.
 */
class RecordHoldRequest extends FormRequest
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
            'sn' => ['required', 'integer', 'min:1'],
            'detail' => [
                'required', 'string',
                'min:'.(int) config('dpc.proceeding.correction_reason_min', 10),
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detail.required' => 'State why this promotion is being held.',
            'detail.min' => 'Please state the reason for holding this promotion in a little more detail.',
        ];
    }
}
