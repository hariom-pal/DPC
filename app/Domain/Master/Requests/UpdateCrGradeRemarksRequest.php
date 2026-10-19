<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit an Eligible CR Grade's remarks — the only field of a grade that changes after
 * it is recorded. Its value and window do not; the audit trail keeps every version.
 */
class UpdateCrGradeRemarksRequest extends FormRequest
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
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['remarks' => __('ui.remarks')];
    }
}
