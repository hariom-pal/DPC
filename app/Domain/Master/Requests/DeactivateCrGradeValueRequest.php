<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\CrGradeValue;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deactivate an Eligible CR Grade: the last day it is valid, and why.
 *
 * Not before today: an earlier end date would take the grade away from DPC years
 * that already used it. Not before the grade's own start either.
 */
class DeactivateCrGradeValueRequest extends FormRequest
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
            'effective_to' => ['required', 'date', 'after_or_equal:today'],
            'deactivation_reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $grade = $this->route('crGrade');

            if (! $grade instanceof CrGradeValue || $validator->errors()->has('effective_to')) {
                return;
            }

            if ($grade->effective_to !== null) {
                $validator->errors()->add('effective_to', __('admin.cr_grades.errors.already_deactivated', ['date' => $grade->effective_to->format('d-m-Y')]));
            } elseif (strtotime((string) $this->input('effective_to')) < $grade->effective_from->getTimestamp()) {
                $validator->errors()->add('effective_to', __('admin.cr_grades.errors.before_start', ['date' => $grade->effective_from->format('d-m-Y')]));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'effective_to' => __('admin.cr_grades.effective_to'),
            'deactivation_reason' => __('admin.cr_grades.deactivation_reason'),
        ];
    }
}
