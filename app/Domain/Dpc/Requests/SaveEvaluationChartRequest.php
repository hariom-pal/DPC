<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Validation\DpcRowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Evaluation / Tabulation Chart (PPT S18) — M4 Phase 5.
 *
 * `reservation_category_id` must resolve to an M2 master row; the CR grade
 * codes and the year bound come from config (Q3), never hardcoded here.
 * Every other field is a plain snapshot value, never validated against any
 * live employee source.
 *
 * No rule here computes or enforces a score, an eligibility gate, or a zone
 * size — the chart may legitimately be saved incomplete (a Circulation DPC
 * is defined by missing CR years). Validation checks shape only; judgement
 * belongs to the M5 Eligibility Engine.
 */
class SaveEvaluationChartRequest extends FormRequest
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
        return array_merge(
            ['rows' => ['array', 'max:'.(int) config('dpc.limits.evaluation_rows')]],
            DpcRowRules::prefix('rows.*.', DpcRowRules::evaluationRow()),
            ['rows.*.cr_grades' => ['array', 'max:'.(int) config('dpc.evaluation.max_cr_years')]],
            DpcRowRules::prefix('rows.*.cr_grades.*.', DpcRowRules::evaluationCrGrade()),
            ['rows.*.loyalty_flags' => ['array']],
            DpcRowRules::prefix('rows.*.loyalty_flags.*.', DpcRowRules::evaluationYearFlag()),
            ['rows.*.property_flags' => ['array']],
            DpcRowRules::prefix('rows.*.property_flags.*.', DpcRowRules::evaluationYearFlag()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.max' => 'A single evaluation chart may hold at most :max officers.',
            'rows.*.cr_grades.max' => 'The chart scores at most :max CR years per officer (PPT S18).',
            'rows.*.cr_grades.*.grade.in' => 'Select a valid CR grade.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $this->input('rows', []);

            $serialNumbers = array_column($rows, 'sn');
            if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
                $validator->errors()->add('rows', 'Each S.N. must be unique within the evaluation chart.');
            }

            foreach ($rows as $index => $row) {
                foreach (['cr_grades' => 'CR', 'loyalty_flags' => 'Loyalty', 'property_flags' => 'Immovable Property'] as $key => $label) {
                    /** @var array<int, array<string, mixed>> $yearRows */
                    $yearRows = $row[$key] ?? [];
                    $years = array_column($yearRows, 'year');

                    if (count($years) !== count(array_unique($years))) {
                        $validator->errors()->add(
                            "rows.{$index}.{$key}",
                            "Each year may appear only once in the {$label} columns for this officer.",
                        );
                    }
                }
            }
        });
    }
}
