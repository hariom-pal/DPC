<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Validation;

use Illuminate\Validation\Rule;

/**
 * The single definition of what one valid officer row looks like, for each
 * proposal section.
 *
 * Manual entry (the Form Requests) and Excel import (M4 Phase 6) both
 * validate against these rule sets — the architect directive for Phase 6 is
 * to reuse the existing layers and never duplicate business logic, and
 * validation rules are business logic. A rule tightened here tightens both
 * paths at once; there is no second copy that can silently drift.
 *
 * Form Requests consume these through `prefix()` to key them under their own
 * array (`officers.*.`, `entries.*.`, `rows.*.`). The importer validates one
 * row at a time and so uses them unprefixed.
 */
final class DpcRowRules
{
    /**
     * Re-key a row rule set under a Form Request's array path.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    public static function prefix(string $prefix, array $rules): array
    {
        $prefixed = [];

        foreach ($rules as $field => $fieldRules) {
            $prefixed[$prefix.$field] = $fieldRules;
        }

        return $prefixed;
    }

    /**
     * Working officer on the target post (PPT S16).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function workingOfficer(): array
    {
        return array_merge(
            ['sn' => ['required', 'integer', 'min:1']],
            self::employeeSnapshot(),
            [
                'date_of_posting_on_post' => ['nullable', 'date'],
                'roster_position' => ['nullable', 'integer', 'min:1'],
                'deputation_date' => ['nullable', 'date'],
            ],
        );
    }

    /**
     * Feeder-post seniority entry (PPT S17).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function seniorityEntry(): array
    {
        return array_merge(
            ['seniority_number' => ['required', 'integer', 'min:1']],
            self::employeeSnapshot(),
            ['date_of_posting_on_current_post' => ['nullable', 'date']],
        );
    }

    /**
     * Evaluation chart row, scalar fields only (PPT S18). The per-year CR /
     * loyalty / property values are validated by the three methods below.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function evaluationRow(): array
    {
        return array_merge(
            ['sn' => ['required', 'integer', 'min:1']],
            self::employeeSnapshot(),
            [
                'date_of_posting_on_current_post' => ['nullable', 'date'],
                'tribal_area_service_years' => ['nullable', 'integer', 'min:0', 'max:100'],
                'note' => ['nullable', 'string', 'max:255'],
            ],
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function evaluationCrGrade(): array
    {
        /** @var list<string> $grades */
        $grades = config('dpc.evaluation.cr_grades');

        return [
            'year' => self::yearRules(),
            'grade' => ['nullable', Rule::in($grades)],
        ];
    }

    /**
     * Loyalty and Immovable Property share one shape (per-year Y/N, decision
     * Q4 — confirmed by the architect on the Phase 5 review).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function evaluationYearFlag(): array
    {
        return [
            'year' => self::yearRules(),
            'flag' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The standard ten-field employee snapshot carried by every proposal
     * snapshot table (architect directive, 2026-08-13). `reservation_category_id`
     * is validated against the live M2 master; nothing else is checked against
     * any employee source, because none exists by design.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function employeeSnapshot(): array
    {
        return [
            'employee_number' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'dob' => ['required', 'date', 'before:today'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'current_post' => ['nullable', 'string', 'max:255'],
            'feeder_post' => ['nullable', 'string', 'max:255'],
            'reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],
            'date_of_joining_service' => ['nullable', 'date'],
            'date_of_posting_on_feeder_post' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function yearRules(): array
    {
        $minYear = (int) config('dpc.evaluation.min_year');
        $maxYear = (int) date('Y') + 1;

        return ['required', 'integer', "min:{$minYear}", "max:{$maxYear}"];
    }
}
