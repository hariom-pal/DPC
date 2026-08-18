<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\Cadre;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class RecruitmentRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Discard feeder rows with no cadre chosen.
     *
     * The grid adds blank rows on demand, so a user who presses Add Feeder Cadre
     * and then thinks better of it without pressing Remove would otherwise submit
     * an empty row and be told to fill in a field they did not want. An empty row
     * is an abandoned row.
     *
     * **Keys are preserved deliberately.** They are the row positions the form
     * rendered, and validation reports errors against them — re-indexing 0, 1, 3
     * to 0, 1, 2 would show the message for the fourth row against the third. The
     * form reads the same keys back out of `old()` for exactly this reason.
     */
    protected function prepareForValidation(): void
    {
        $feeders = $this->input('feeders');

        if (! is_array($feeders)) {
            return;
        }

        $kept = array_filter(
            $feeders,
            static fn ($feeder): bool => is_array($feeder)
                && trim((string) ($feeder['feeder_cadre_id'] ?? '')) !== '',
        );

        // Default the experience unit rather than requiring it on the wire.
        //
        // The grid always posts one (its dropdown has no blank option), so this
        // costs the UI nothing. It exists so that a caller written before the unit
        // existed — the previous shape of this endpoint, and its tests — keeps
        // working: every value ever stored in `min_experience_years` was years, so
        // years is the only defaulting that cannot change a rule's meaning.
        foreach ($kept as $key => $feeder) {
            if (trim((string) ($feeder['min_experience_unit'] ?? '')) === '') {
                $kept[$key]['min_experience_unit'] = ExperienceUnit::Years->value;
            }
        }

        $this->merge(['feeders' => $kept]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cadre_id' => ['required', Rule::exists('cadres', 'id')],
            'government_order_number' => ['required', 'string', 'max:255'],
            'direct_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'promotion_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(MasterStatus::class)],
            'go_document' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:10240'],

            // A rule that fills any share of its posts by promotion must name
            // where those officers are promoted from. A rule with promotion at 0%
            // — a cadre recruited entirely directly — legitimately has no feeder,
            // so the requirement is conditional rather than absolute.
            'feeders' => ['array', Rule::requiredIf(fn (): bool => $this->hasPromotionQuota())],

            'feeders.*.feeder_cadre_id' => [
                'required',
                'distinct',                          // no duplicate feeder cadre
                'different:cadre_id',                // a cadre cannot feed itself
                Rule::exists('cadres', 'id'),
            ],
            // Promotion Share (%) — this feeder's slice of the promotion quota.
            // The individual bound is 0..100; that they total exactly 100 is
            // checked in withValidator(), because it is a property of the set.
            'feeders.*.promotion_percentage' => ['required', 'numeric', 'between:0,100'],

            // Minimum Experience: a value and the unit it counts in. Negative is
            // impossible by `min:0`; the cap depends on the unit (60 years, or
            // 720 months, which is the same ceiling expressed either way).
            'feeders.*.min_experience_years' => ['required', 'integer', 'min:0', 'max:720'],
            'feeders.*.min_experience_unit' => ['required', Rule::in(ExperienceUnit::values())],

            // Optional — a Schedule-IV feeder may impose no qualification beyond
            // holding the feeder post — but when given it must be a live master
            // row: Active, and not soft-deleted. A withdrawn qualification cannot
            // be attached to a new rule while remaining on the rules that already
            // cite it.
            //
            // `whereNull('deleted_at')` is not redundant. `Rule::exists` runs on
            // the query builder, not Eloquent, so the SoftDeletes global scope
            // never applies and a trashed row would otherwise validate — its
            // `status` column is still 'active'.
            'feeders.*.qualification_id' => [
                'nullable',
                Rule::exists('qualifications', 'id')
                    ->where('status', MasterStatus::Active->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * The checks that are properties of the feeder set as a whole, which
     * per-field rules cannot express.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $feeders = $this->input('feeders');
            $feeders = is_array($feeders) ? $feeders : [];

            $this->validateRecruitmentSplit($validator);
            $this->validateSameDepartment($validator, $feeders);
            $this->validateShareTotal($validator, $feeders);
        });
    }

    /**
     * Direct Recruitment % + Promotion % must total exactly 100%.
     *
     * Every sanctioned post in a cadre is filled one way or the other, so the
     * two shares describe the whole cadre and have to account for all of it.
     * The PPT states it by example rather than in words — every row of S9 and
     * S11 reads 0 / 100, and the seeded Schedule-IV ladder is 100/0, 50/50,
     * 25/75 and 0/100 — and no worked example anywhere in either deck leaves a
     * remainder. Requested explicitly by business, 2026-08-18.
     *
     * Reported against its own key: neither number is wrong on its own, and
     * attaching the message to one of them would tell the user to change the
     * field they may not have meant to.
     *
     * **This is a write-path rule only.** Existing rows are never rewritten —
     * a rule stored before this check is still read, listed and used; it is
     * only on saving one that the totals must balance. That is deliberate:
     * silently rescaling a Government Order's own percentages would be
     * inventing policy, which the Promotion Share migration explicitly refused
     * to do for the same reason.
     */
    private function validateRecruitmentSplit(Validator $validator): void
    {
        $direct = $this->input('direct_recruitment_pct');
        $promotion = $this->input('promotion_recruitment_pct');

        // Absent or non-numeric is already reported by the field rules; saying
        // so twice helps nobody.
        if (! is_numeric($direct) || ! is_numeric($promotion)) {
            return;
        }

        $total = (float) $direct + (float) $promotion;

        if (abs($total - 100.0) < 0.01) {
            return;
        }

        $validator->errors()->add('recruitment_split', sprintf(
            'Direct Recruitment %% and Promotion %% must total exactly 100%%. They currently total %s%%%s.',
            self::trim($total),
            $total < 100.0
                ? ' — '.self::trim(100.0 - $total).'% of the posts is unaccounted for'
                : ' — over-allocated by '.self::trim($total - 100.0).'%',
        ));
    }

    /**
     * A percentage as a human would write it: 50, 33.33, 66.67 — never 50.00.
     */
    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * A feeder must belong to the same department as the target cadre.
     *
     * Cadres are department-scoped, so a rule promoting into School Education
     * from a Health Department cadre is not a stricter-than-necessary rule — it
     * is a corrupt one. Verified against existing data as clean before this was
     * added.
     *
     * @param  array<array-key, mixed>  $feeders
     */
    private function validateSameDepartment(Validator $validator, array $feeders): void
    {
        $targetDepartment = Cadre::query()->whereKey($this->input('cadre_id'))->value('department_id');

        if ($targetDepartment === null || $feeders === []) {
            return;
        }

        $departments = Cadre::query()
            ->whereIn('id', array_filter(array_column($feeders, 'feeder_cadre_id')))
            ->pluck('department_id', 'id');

        foreach ($feeders as $index => $feeder) {
            $cadreId = is_array($feeder) ? ($feeder['feeder_cadre_id'] ?? null) : null;

            if ($cadreId !== null && ($departments[$cadreId] ?? $targetDepartment) !== $targetDepartment) {
                $validator->errors()->add(
                    "feeders.{$index}.feeder_cadre_id",
                    'A feeder cadre must belong to the same department as the target cadre.',
                );
            }
        }
    }

    /**
     * The Promotion Share column must total exactly 100%.
     *
     * A share is this feeder's slice of the promotion quota, so the slices have
     * to account for all of it — 100% of the quota, not 100% of the cadre. The
     * error names the actual total, because "must equal 100" without saying what
     * it currently equals makes the user add the column up by hand.
     *
     * Skipped when the rule has no promotion quota: there is nothing to divide,
     * and `feeders` is already required-if-promotion above, so an empty set is
     * reported there rather than twice.
     *
     * @param  array<array-key, mixed>  $feeders
     */
    private function validateShareTotal(Validator $validator, array $feeders): void
    {
        if ($feeders === [] || ! $this->hasPromotionQuota()) {
            return;
        }

        $total = 0.0;
        foreach ($feeders as $feeder) {
            if (is_array($feeder) && is_numeric($feeder['promotion_percentage'] ?? null)) {
                $total += (float) $feeder['promotion_percentage'];
            }
        }

        // A hundredth of a percent of tolerance, so 33.33 + 33.33 + 33.34 passes.
        if (abs($total - 100.0) < 0.01) {
            return;
        }

        $validator->errors()->add('feeders', sprintf(
            'Promotion Share must total exactly 100%% across all feeder cadres. It currently totals %s%%%s.',
            self::trim($total),
            $total < 100.0 ? ' — '.self::trim(100.0 - $total).'% is unallocated' : '',
        ));
    }

    /**
     * Does this rule fill any of its posts by promotion?
     *
     * The feeder list, and the 100% total that describes how it is divided, only
     * mean anything if it does.
     */
    private function hasPromotionQuota(): bool
    {
        return (float) $this->input('promotion_recruitment_pct', 0) > 0;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'feeders.required' => 'Name at least one feeder cadre. A rule that promotes into this post must say which post officers are promoted from — or set Promotion Recruitment % to 0 if the post is filled entirely by direct recruitment.',
            'feeders.*.feeder_cadre_id.distinct' => 'This feeder cadre is already listed. Remove the duplicate row.',
            'feeders.*.feeder_cadre_id.different' => 'The target cadre cannot also be its own feeder cadre.',
            'feeders.*.qualification_id.exists' => 'Select a qualification that is Active in the Qualification master.',
            'feeders.*.min_experience_years.min' => 'Minimum experience cannot be negative.',
            'feeders.*.min_experience_unit.in' => 'Choose Years or Months.',
        ];
    }
}
