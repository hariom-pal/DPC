<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new version of the CR marks and qualifying scores (rules 3.4, 3.5).
 *
 * The grades are the five the CR is graded in (`dpc.evaluation.cr_grades`); the
 * Administrator gives each its marks. A scale must rank the grades — a better grade
 * never earns fewer marks — and a qualifying score must be reachable within the
 * scoring years, or no officer could ever qualify.
 */
class RecordCrScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function grades(): array
    {
        return array_values((array) config('dpc.evaluation.cr_grades', []));
    }

    /**
     * @return list<string>
     */
    public static function classPairs(): array
    {
        return array_values(array_unique([
            ...array_keys((array) config('dpc.scoring.qualifying_scores', [])),
            'default',
        ]));
    }

    /**
     * @return list<string>
     */
    public static function gatedPairs(): array
    {
        return array_keys((array) config('dpc.scoring.gates.last_year_forbidden_grade', []));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $grades = self::grades();
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'effective_from_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'grade_marks' => ['required', 'array'],
            'scoring_years' => ['required', 'integer', 'min:1', 'max:10'],
            'tribal_area_bonus' => ['required', 'integer', 'min:0', 'max:10'],
            'disqualifying_grade' => ['required', Rule::in($grades)],
            'super_seat_grade' => ['required', Rule::in($grades)],
            'last_year_forbidden_grades' => ['nullable', 'array'],
            'qualifying_scores' => ['required', 'array'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];

        foreach ($grades as $grade) {
            $rules["grade_marks.{$grade}"] = ['required', 'integer', 'min:0', 'max:100'];
        }

        foreach (self::classPairs() as $pair) {
            $rules["qualifying_scores.{$pair}"] = ['required', 'integer', 'min:0', 'max:1000'];
        }

        foreach (self::gatedPairs() as $pair) {
            $rules["last_year_forbidden_grades.{$pair}"] = ['nullable', Rule::in($grades)];
        }

        return $rules;
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $marks = array_map('intval', (array) $this->input('grade_marks'));
            $grades = self::grades();

            for ($i = 1; $i < count($grades); $i++) {
                if ($marks[$grades[$i]] >= $marks[$grades[$i - 1]]) {
                    $validator->errors()->add("grade_marks.{$grades[$i]}", __('dpcrules.errors.marks_must_fall', [
                        'grade' => $grades[$i],
                        'better' => $grades[$i - 1],
                    ]));
                }
            }

            $highest = max($marks) * (int) $this->input('scoring_years') + (int) $this->input('tribal_area_bonus');

            foreach (self::classPairs() as $pair) {
                if ((int) $this->input("qualifying_scores.{$pair}") > $highest) {
                    $validator->errors()->add("qualifying_scores.{$pair}", __('dpcrules.errors.qualifying_unreachable', ['highest' => $highest]));
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'name' => __('dpcrules.cr.name'),
            'effective_from_year' => __('dpcrules.effective_from_year'),
            'scoring_years' => __('dpcrules.cr.scoring_years'),
            'tribal_area_bonus' => __('dpcrules.cr.tribal_area_bonus'),
            'disqualifying_grade' => __('dpcrules.cr.disqualifying_grade'),
            'super_seat_grade' => __('dpcrules.cr.super_seat_grade'),
            'order_number' => __('dpcrules.order_number'),
            'order_date' => __('dpcrules.order_date'),
            'document' => __('dpcrules.document'),
            'remarks' => __('dpcrules.remarks'),
        ];

        foreach (self::grades() as $grade) {
            $attributes["grade_marks.{$grade}"] = __('dpcrules.cr.marks_for', ['grade' => $grade]);
        }

        foreach (self::classPairs() as $pair) {
            $attributes["qualifying_scores.{$pair}"] = __('dpcrules.cr.qualifying_for', ['pair' => $pair === 'default' ? __('dpcrules.cr.other_pairs') : $pair]);
        }

        foreach (self::gatedPairs() as $pair) {
            $attributes["last_year_forbidden_grades.{$pair}"] = __('dpcrules.cr.last_year_forbidden_for', ['pair' => $pair]);
        }

        return $attributes;
    }
}
