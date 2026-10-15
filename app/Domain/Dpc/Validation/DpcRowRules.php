<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Validation;

use App\Domain\Dpc\Models\DpcWorkingOfficer;
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
            $prefixed[$prefix.$field] = array_map(
                static fn (mixed $rule): mixed => self::prefixSiblingReference($prefix, $rule, array_keys($rules)),
                $fieldRules,
            );
        }

        return $prefixed;
    }

    /**
     * Rewrite a rule's reference to a **sibling field** so it resolves inside the
     * row rather than at the root of the request.
     *
     * `required_unless:status,vacant` is correct for the importer, which validates
     * one row as a flat array. Keyed under `officers.*.name` it is not: Laravel
     * looks `status` up at the top level of the payload, finds nothing, and the
     * rule passes vacuously — or, as with `required_unless`, fires on every row.
     * The fix is `officers.*.status`, where Laravel substitutes the row's own
     * index.
     *
     * **Only names that are themselves fields of this rule set are rewritten**, so
     * `before:today` and `min:1` are left alone. Guessing more broadly would have
     * turned `today` into `officers.*.today`.
     *
     * @param  list<string>  $fields
     */
    private static function prefixSiblingReference(string $prefix, mixed $rule, array $fields): mixed
    {
        if (! is_string($rule) || ! str_contains($rule, ':')) {
            return $rule;
        }

        [$name, $parameters] = explode(':', $rule, 2);

        $rewritten = array_map(
            static fn (string $parameter): string => in_array($parameter, $fields, true)
                ? $prefix.$parameter
                : $parameter,
            explode(',', $parameters),
        );

        return $name.':'.implode(',', $rewritten);
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

            /*
             | A row is an officer or an empty chair (business directive,
             | 2026-09-02). Defaulted rather than required, in
             | `SaveWorkingOfficersRequest::prepareForValidation()` and in the
             | importer: a chart that names only officers is the commonest case and
             | the one every existing file carries, so omitting the column has to
             | keep meaning `working`.
             */
            ['status' => ['required', Rule::in([DpcWorkingOfficer::STATUS_WORKING, DpcWorkingOfficer::STATUS_VACANT])]],

            /*
             | How the officer came into the post (business directive, 2026-09-02):
             | `promotion`, `deputation` or `direct`. It decides which of the
             | Recruitment Rule's two quotas his chair is consuming, so it is
             | required of an officer and refused of an empty chair — nobody filled
             | a vacant post, and a provenance recorded for one would be fiction.
             */
            ['source' => [
                'required_unless:status,'.DpcWorkingOfficer::STATUS_VACANT,
                'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT,
                'nullable',
                Rule::in(DpcWorkingOfficer::sources()),
            ]],

            /*
             | **`name` and `dob` are required of an officer and refused of a chair.**
             |
             | `required_unless` rather than `nullable`, so a `working` row still
             | cannot arrive without them — relaxing the rule for every row would
             | have let a nameless officer through to a signed proposal. And
             | `prohibited_if` on the vacant side, because a name typed against a
             | vacant post is either a mistake or a fiction, and the chart is
             | evidence.
             */
            self::optional(self::employeeSnapshot()),
            [
                'name' => ['required_unless:status,'.DpcWorkingOfficer::STATUS_VACANT, 'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT, 'nullable', 'string', 'max:255'],
                'dob' => ['required_unless:status,'.DpcWorkingOfficer::STATUS_VACANT, 'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT, 'nullable', 'date', 'before:today'],

                /*
                 | The category stays required on **both**. On an officer it is that
                 | officer's; on a vacant post it is the category the roster holds
                 | that point for — and it is the only thing a vacant row carries
                 | that the engine needs, because the reserved-category analysis is
                 | fed from it.
                 */
                'reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],

                /*
                 | The seat's category — the roster point the officer was appointed
                 | against (business instruction, 2026-09-07). Optional where the
                 | officer's own category is required: a department that keeps no
                 | roster has nothing to put here, and blank means *"not recorded"*
                 | rather than "a point of their own category".
                 */
                'seat_reservation_category_id' => ['nullable', Rule::exists('reservation_categories', 'id')],

                /*
                 | The officer's mobile number (business instruction, 2026-09-07).
                 | Ten digits starting 6-9, the shape the rest of the portal accepts
                 | — it is what a Promotion Candidate signs in with, so a number that
                 | is not a number is an account nobody can reach.
                 |
                 | Optional: a department may not hold one for every officer, and a
                 | seniority list is not refused over it.
                 */
                'mobile' => ['nullable', 'string', 'regex:/^[6-9]\\d{9}$/'],

                /*
                 | Retirement and deputation belong to a person. A vacant post has
                 | nobody to retire and nobody to send away, and a date there would
                 | be counted as a second vacancy on a post that is already empty.
                 */
                'date_of_retirement' => ['nullable', 'date', 'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT],
                'deputation_from' => ['nullable', 'date', 'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT],

                'date_of_posting_on_promotion_to_post' => ['nullable', 'date'],
                'roster_position' => ['nullable', 'integer', 'min:1'],

                /*
                 | Change register of 2026-08-27, items 3 and 4.
                 |
                 | All three are nullable: most officers on a chart are neither
                 | retiring in the DPC Year nor on deputation, and demanding the
                 | dates for every row would make the upload harder for nothing.
                 | The engine simply counts no vacancy for a row that carries none.
                 |
                 | `deputation_to` is validated `after_or_equal` its start and not
                 | `required_with`, because **a deputation with no return date is
                 | the commonest case and the one that frees a post** — an officer
                 | still away. Requiring an end date would make the clearest
                 | instance of item 4 unrecordable.
                 */
                'deputation_to' => ['nullable', 'date', 'after_or_equal:deputation_from', 'prohibited_if:status,'.DpcWorkingOfficer::STATUS_VACANT],
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
            ['date_of_posting_on_promotion_to_post' => ['nullable', 'date']],
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
        /*
         * **Identity is accepted but not required here** (business directive,
         * 2026-08-22). The chart's ten employee fields are projected from the
         * approved Seniority List, so the only field this row must carry is the
         * `sn` that identifies which projected officer it refers to.
         *
         * The identity rules are kept, as `nullable`, for one reason: the
         * pre-filled template the Authorized Officer downloads *contains* those
         * columns, and the officer uploads the file back unchanged. Dropping the
         * rules would leave that data unvalidated on its way past; requiring them
         * would refuse a file whose identity columns had been deleted, which is
         * harmless because the Seniority List governs either way.
         *
         * `employee_number` is the exception that matters: it is compared against
         * the Seniority List and a mismatch is refused. That check needs the
         * proposal, so it lives in SaveEvaluationChartRequest and
         * EvaluationCandidateService rather than in a per-field rule.
         */
        return array_merge(
            ['sn' => ['required', 'integer', 'min:1']],
            self::optional(self::employeeSnapshot()),
            [
                'date_of_posting_on_promotion_to_post' => ['nullable', 'date'],
                'tribal_area_service_years' => ['nullable', 'integer', 'min:0', 'max:100'],
                'note' => ['nullable', 'string', 'max:255'],
            ],
        );
    }

    /**
     * The same rules with every `required` relaxed to `nullable`.
     *
     * Derived rather than written out a second time, so a field added to the
     * ten-field employee snapshot cannot be validated on one screen and
     * unvalidated on another.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private static function optional(array $rules): array
    {
        return array_map(
            static fn (array $fieldRules): array => array_values(array_unique(array_merge(
                ['nullable'],
                array_filter($fieldRules, static fn (mixed $rule): bool => $rule !== 'required'),
            ), SORT_REGULAR)),
            $rules,
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

            /*
             | Whether the officer served in a tribal area during *this* year
             | (change register of 2026-08-27, item 18).
             |
             | > *Tribal Area benefit shall always be linked with the same CR year.
             | > No Tribal benefit shall be carried forward to another year.*
             |
             | Nullable rather than defaulting false, so "not recorded" and
             | "recorded as no" stay different facts — a chart filled in before this
             | register has neither, and inventing a year would be inventing the
             | attribution the item asks for.
             */
            'tribal_area' => ['nullable', 'boolean'],
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
            /*
             | A Post is a master entity, and is never typed (DPC-MODULE-FIX-001).
             |
             | **Two accepted shapes, deliberately, and they are not equivalent.**
             |
             | `*_post_id` is what the **screens** now send: an id chosen from the
             | Department Post master, which must be an active mapping still in
             | force. `DpcProposalService` resolves it to that master row's name
             | and stores the name — so the officer snapshot keeps holding a name,
             | as it always has, and no column changes shape.
             |
             | `*_post` — the free-text name — is retained **only** for the CSV
             | importers and for payloads written before this change. A spreadsheet
             | carries a post *name* because a department filling one in has no
             | access to our primary keys, exactly as `reservation_category` is a
             | code on a sheet and an id on a form. Refusing the name here would
             | break every existing workbook, which is the one thing this fix must
             | not do.
             |
             | The id wins where both arrive. Neither is required, because a row on
             | a single-feeder proposal need name no post at all — the pair is
             | already fixed by the Pre-DPC the proposal was raised from.
             */
            'promotion_to_post_id' => ['nullable', 'integer', Rule::exists('department_posts', 'id')
                ->where('status', 'active')
                ->whereNotNull('active_flag')
                ->whereNull('deleted_at')],
            'promotion_from_post_id' => ['nullable', 'integer', Rule::exists('department_posts', 'id')
                ->where('status', 'active')
                ->whereNotNull('active_flag')
                ->whereNull('deleted_at')],

            'promotion_to_post' => ['nullable', 'string', 'max:255'],
            'promotion_from_post' => ['nullable', 'string', 'max:255'],
            'reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],

            /*
                 | The seat's category — the roster point the officer was appointed
                 | against (business instruction, 2026-09-07). Optional where the
                 | officer's own category is required: a department that keeps no
                 | roster has nothing to put here, and blank means *"not recorded"*
                 | rather than "a point of their own category".
                 */
            'seat_reservation_category_id' => ['nullable', Rule::exists('reservation_categories', 'id')],

            /*
                 | The officer's mobile number (business instruction, 2026-09-07).
                 | Ten digits starting 6-9, the shape the rest of the portal accepts
                 | — it is what a Promotion Candidate signs in with, so a number that
                 | is not a number is an account nobody can reach.
                 |
                 | Optional: a department may not hold one for every officer, and a
                 | seniority list is not refused over it.
                 */
            'mobile' => ['nullable', 'string', 'regex:/^[6-9]\\d{9}$/'],
            'date_of_joining_service' => ['nullable', 'date'],
            'date_of_posting_on_promotion_from_post' => ['nullable', 'date'],
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
