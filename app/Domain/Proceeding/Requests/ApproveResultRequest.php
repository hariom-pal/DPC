<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;

/**
 * APPROVE BY CGPSC (PPT S34) — M7 Phase 3.
 *
 * `promotion_as_on_year` is **captured, never derived** (architect ruling
 * AQ-7). The vacancy position year and the proposal year are not the same
 * thing — the PPT's own examples differ, S16 saying 2026 and S34 saying 2017 —
 * so deriving it would be silently wrong on every published result.
 */
class ApproveResultRequest extends FormRequest
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
            'promotion_as_on_year' => ['required', 'integer', 'digits:4', 'min:1950', 'max:'.((int) date('Y') + 1)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'promotion_as_on_year.required' => 'State the year the promotion is made as on. It appears in the heading of the published result.',
        ];
    }
}
