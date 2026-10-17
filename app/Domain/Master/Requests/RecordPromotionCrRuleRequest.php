<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Services\PromotionCrRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new promotion CR rule for a class. Marks are chosen from the Eligible CR Grades
 * valid on 1 January of the rule's first DPC year.
 */
class RecordPromotionCrRuleRequest extends FormRequest
{
    public const MAX_YEARS = 10;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $to = $this->route('classMaster');
        $from = $to instanceof ClassMaster
            ? array_map(fn (ClassMaster $class): string => $class->code, app(PromotionCrRules::class)->fromClasses($to))
            : [];
        $grades = app(PromotionCrRules::class)->gradesForYear((int) $this->input('effective_from_year'));

        return [
            'from_class' => ['required', Rule::in($from)],
            'effective_from_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'cr_years' => ['required', 'integer', 'min:1', 'max:'.self::MAX_YEARS],
            'loyalty_years' => ['required', 'integer', 'min:0', 'max:'.self::MAX_YEARS],
            'property_years' => ['required', 'integer', 'min:0', 'max:'.self::MAX_YEARS],
            'qualifying_average' => ['required', 'numeric', 'min:0', 'max:1000'],
            'super_seat_grade' => ['nullable', 'integer', Rule::in($grades)],
            'tribal_bonus' => ['required', 'integer', 'min:0', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'years' => ['nullable', 'array'],
            'years.*.cr_min_marks' => ['nullable', 'integer', Rule::in($grades)],
            'years.*.cr_mandatory' => ['nullable', 'boolean'],
            'years.*.loyalty_mandatory' => ['nullable', 'boolean'],
            'years.*.property_mandatory' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $year = (int) $this->input('effective_from_year');

            if (! $validator->errors()->has('effective_from_year') && app(PromotionCrRules::class)->gradesForYear($year) === []) {
                $validator->errors()->add('effective_from_year', __('admin.promotion_rules.errors.no_grades', ['year' => $year]));

                return;
            }

            // Every CR year needs its passing marks.
            for ($before = 1; $before <= min((int) $this->input('cr_years'), self::MAX_YEARS); $before++) {
                if ((string) $this->input("years.{$before}.cr_min_marks", '') === '' && ! $validator->errors()->has("years.{$before}.cr_min_marks")) {
                    $validator->errors()->add("years.{$before}.cr_min_marks", __('admin.promotion_rules.errors.marks_required', ['year' => $year - $before]));
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
            'from_class' => __('admin.promotion_rules.from_class'),
            'effective_from_year' => __('admin.promotion_rules.effective_from_year'),
            'cr_years' => __('admin.promotion_rules.cr_years'),
            'loyalty_years' => __('admin.promotion_rules.loyalty_years'),
            'property_years' => __('admin.promotion_rules.property_years'),
            'qualifying_average' => __('admin.promotion_rules.qualifying_average'),
            'super_seat_grade' => __('admin.promotion_rules.super_seat_grade'),
            'tribal_bonus' => __('admin.promotion_rules.tribal_bonus'),
        ];

        for ($before = 1; $before <= self::MAX_YEARS; $before++) {
            $attributes["years.{$before}.cr_min_marks"] = __('admin.promotion_rules.cr_min_marks');
        }

        return $attributes;
    }

    protected function prepareForValidation(): void
    {
        $years = [];

        foreach ((array) $this->input('years', []) as $before => $row) {
            $row = (array) $row;
            $years[$before] = [
                'cr_min_marks' => $row['cr_min_marks'] ?? '',
                'cr_mandatory' => filter_var($row['cr_mandatory'] ?? false, FILTER_VALIDATE_BOOL),
                'loyalty_mandatory' => filter_var($row['loyalty_mandatory'] ?? false, FILTER_VALIDATE_BOOL),
                'property_mandatory' => filter_var($row['property_mandatory'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        $this->merge(['years' => $years, 'tribal_bonus' => $this->input('tribal_bonus', 0) === '' ? 0 : $this->input('tribal_bonus', 0)]);
    }
}
