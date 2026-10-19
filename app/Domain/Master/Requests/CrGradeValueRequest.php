<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\CrGradeValue;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            // The CR grade this value stands for (e.g. क+ = 9), when it is one.
            'grade_code' => ['nullable', 'string', 'max:10', Rule::in((array) config('dpc.evaluation.cr_grades'))],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * One window at a time per value: a value still valid on the new start date has
     * to be deactivated first, so its windows never overlap.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::parse((string) $this->input('effective_from'))->startOfDay();

            $open = CrGradeValue::query()
                ->where('value', $this->integer('value'))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->exists();

            if ($open) {
                $validator->errors()->add('value', __('admin.cr_grades.errors.still_valid'));
            }
        });
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
