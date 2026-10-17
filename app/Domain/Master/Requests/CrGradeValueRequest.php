<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrGradeValueRequest extends FormRequest
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
            'value' => ['required', 'integer', 'min:0', 'max:1000',
                Rule::unique('cr_grade_values', 'value')->where(fn ($query) => $query->whereDate('effective_from', (string) $this->input('effective_from')))],
            'effective_from' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'value' => __('admin.cr_grades.value'),
            'effective_from' => __('admin.cr_grades.effective_from'),
        ];
    }
}
