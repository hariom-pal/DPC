<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Requests;

use App\Domain\Eligibility\Services\PromotionHierarchyService;
use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;

/**
 * One promotion exercise: department + DPC Year + Promotion From Post +
 * Promotion To Post, and the terms it sanctions (change items 8–10).
 *
 * **Three fields the officer does not send, and would be ignored if they did.**
 * Pay Scale, Class and Promotion Duration are read off the two posts by
 * `PostAttributeResolver` (item 5). The form shows them as soon as both posts are
 * chosen — that is what "no manual selection should be required" means — but the
 * *stored* values come from the master, so a crafted request cannot sanction a
 * pay scale the Commission never approved.
 */
class SavePreDpcProposalRequest extends FormRequest
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
        $departmentId = $this->user()?->department_id;

        /*
         | A post must be a *live post of the officer's own department*.
         |
         | The department scope is the security half: without it an Authorized
         | Officer could name Forest's Deputy Secretary in Higher Education's
         | proposal. The `active` status is the correctness half: a draft post has
         | not been sanctioned, and an exercise built on it would inherit a pay
         | scale nobody approved.
         */
        $inDepartment = fn (): Exists => Rule::exists('department_posts', 'id')
            ->where('department_id', $departmentId)
            ->where('status', MasterStatus::Active->value)
            // The mapping in force. A superseded one is inactive as well, so this
            // is belt and braces — but it says what is meant.
            ->whereNotNull('active_flag')
            ->whereNull('deleted_at');

        return [
            /*
             | DPC Year (item 8).
             |
             | Bounded rather than free: a four-digit year is the only thing this
             | can be, and the window stops a typo becoming an exercise filed
             | under 2062. The upper bound is next year, because a department
             | legitimately prepares the coming year's exercise in advance, and no
             | further, because there is nothing to prepare beyond that.
             */
            // Rule 1.4: nothing before 2019 is taken in this software.
            'dpc_year' => ['required', 'integer', 'min:'.(int) config('dpc.earliest_dpc_year', 2019), 'max:'.((int) now()->addYear()->format('Y'))],

            'promotion_to_post_id' => ['required', 'integer', $inDepartment()],

            /*
             | The Promotion From Posts — one row per feeder (restored 2026-08-25).
             |
             | `required` and `min:1`: an order that sanctions promotion into a post
             | without saying what it is filled from sanctions nothing. Bounded at 20
             | because a Schedule-IV entry with twenty feeders is a data-entry
             | accident, not a rule.
             */
            'promotion_sources' => ['required', 'array', 'min:1', 'max:20'],
            'promotion_sources.*.promotion_from_post_id' => [
                'required', 'integer', 'different:promotion_to_post_id', $inDepartment(),
            ],

            // Each feeder's share of the promotion quota. Validated as a set below.
            'promotion_sources.*.promotion_percentage' => ['required', 'numeric', 'min:0', 'max:100'],

            // Per feeder, because Schedule-IV sets it per feeder: five years as an
            // Assistant Director is not five years as a Research Officer.
            'promotion_sources.*.min_experience_years' => ['required', 'integer', 'min:0', 'max:40'],
            'promotion_sources.*.experience_unit' => ['required', new Enum(ExperienceUnit::class)],

            /*
             | **Required since 2026-09-06** (business instruction: *"promotion to
             | post mandatory and qualification is also"*).
             |
             | It was `nullable` until then, on the reading that a feeder may impose
             | no qualification beyond holding the Promotion From Post. Business has
             | ruled otherwise: a Schedule-IV entry states what it accepts, and a
             | blank one leaves the eligibility engine with no qualification to test
             | a candidate against.
             |
             | **Plural since 2026-08-26** (change register item 11) — a feeder
             | accepts any of a list of degrees. Each entry must be a live master row.
             */
            'promotion_sources.*.qualification_ids' => ['required', 'array', 'min:1'],
            'promotion_sources.*.qualification_ids.*' => [
                'integer',
                Rule::exists('qualifications', 'id')
                    ->where('status', MasterStatus::Active->value)
                    ->whereNull('deleted_at'),
            ],

            // The Schedule-IV split. Validated as a pair below.
            'direct_recruitment_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'promotion_recruitment_pct' => ['required', 'numeric', 'min:0', 'max:100'],

            'government_order_number' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertSplitTotalsOneHundred($validator);
            $this->assertSourcesAreDistinct($validator);
            $this->assertSourceSharesTotalOneHundred($validator);
            $this->assertEveryFeederPromotesUpward($validator);
            $this->assertExerciseIsNotAlreadyActive($validator);
        });
    }

    /**
     * Every feeder is genuinely below the post it feeds (business clarification,
     * 2026-09-06).
     *
     * > *A Pre-DPC always goes from a lower grade to a higher grade, never the other
     * > way. The one exemption is Class-I to Class-I, and that only to a higher pay
     * > scale. Class-II to Class-I, Class-III to Class-II — but never Class-I to
     * > Class-II.*
     *
     * **The rule already existed; it was asked far too late.**
     * {@see PromotionHierarchyService} has judged post pairs since the register of
     * 2026-08-27, but only `DpcCalculationService` consulted it — which means an
     * officer could sanction a Class-I into Class-II exercise, get it approved by
     * the Administrative Head, raise a DPC from it, import a seniority list against
     * it, and be told at *calculation* that the promotion was a demotion. Asking
     * here refuses the pair at the one moment somebody is still choosing it.
     *
     * **Per feeder, and reported against that feeder's own field.** A Schedule-IV
     * post is fed from several, and "one of your feeders is wrong" is not something
     * an officer can act on when there are five rows on screen.
     *
     * Silent when the pair cannot be resolved — a missing or out-of-department post
     * is already refused by the field rules above, and adding a second message about
     * the same fault helps nobody.
     */
    private function assertEveryFeederPromotesUpward(Validator $validator): void
    {
        $to = DepartmentPost::query()->with('payScale')->find((int) $this->input('promotion_to_post_id', 0));

        if ($to === null) {
            return;
        }

        $hierarchy = app(PromotionHierarchyService::class);

        foreach ((array) $this->input('promotion_sources', []) as $index => $source) {
            $from = DepartmentPost::query()->with('payScale')->find((int) ($source['promotion_from_post_id'] ?? 0));

            if ($from === null) {
                continue;
            }

            $verdict = $hierarchy->verdict($from, $to);

            if ($verdict['valid']) {
                continue;
            }

            /*
             | The service's own words, prefixed with the two posts.
             |
             | It states which of its comparisons refused the pair — class, pay scale,
             | grade pay, or the same-class rule — and that is exactly what the
             | officer needs in order to know whether they picked the wrong feeder or
             | the master carries the wrong class.
             */
            $validator->errors()->add(
                "promotion_sources.{$index}.promotion_from_post_id",
                __('predpc.validation.feeder_not_below_post', [
                    'from' => $from->name,
                    'to' => $to->name,
                    'reason' => implode(' ', $verdict['failures']),
                ]),
            );
        }
    }

    /**
     * A post appears once among the sources.
     *
     * Two rows for one feeder would carry two shares and two qualifying periods for
     * the same officers, and the database's own unique index would refuse the save
     * with a message about an index. Caught here so the officer is told which post.
     */
    private function assertSourcesAreDistinct(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('promotion_sources', []) as $index => $source) {
            $postId = (int) ($source['promotion_from_post_id'] ?? 0);

            if ($postId === 0) {
                continue;
            }

            if (in_array($postId, $seen, true)) {
                $validator->errors()->add(
                    "promotion_sources.{$index}.promotion_from_post_id",
                    'This post is already named as a Promotion From Post. Each post appears once, with one set of terms.',
                );
            }

            $seen[] = $postId;
        }
    }

    /**
     * The feeders' shares must total exactly 100% of the promotion quota.
     *
     * **Of the promotion quota, not of all posts** — the same rule the Recruitment
     * Rule master enforces, and for the same reason: the engine fills promotion
     * seats from these shares, so a set totalling 80% leaves a fifth of the promotion
     * vacancies sanctioned by nothing. Compared on integer paise so `33.33 + 33.33 +
     * 33.34` is accepted and floating-point noise is not mistaken for a shortfall.
     *
     * Skipped when the post is filled entirely by direct recruitment, because then
     * there is no promotion quota to divide.
     */
    private function assertSourceSharesTotalOneHundred(Validator $validator): void
    {
        if ((float) $this->input('promotion_recruitment_pct', 0) <= 0.0) {
            return;
        }

        $sources = (array) $this->input('promotion_sources', []);

        if ($sources === []) {
            return;
        }

        $total = 0;

        foreach ($sources as $source) {
            $total += (int) round(((float) ($source['promotion_percentage'] ?? 0)) * 100);
        }

        if ($total === 10000) {
            return;
        }

        $validator->errors()->add('promotion_sources', sprintf(
            'The Promotion From Posts must share exactly 100%% of the promotion quota between them. '
            .'They currently total %s%%.',
            rtrim(rtrim(number_format($total / 100, 2, '.', ''), '0'), '.'),
        ));
    }

    /**
     * Direct + Promotion must be exactly 100%.
     *
     * The same rule the Recruitment Rule master enforces, for the same reason: a
     * post whose split totals 80% has 20% of its vacancies sanctioned by nothing,
     * and the engine fills seats from these shares. Compared on integer paise so
     * `33.33 + 66.67` is accepted and floating-point noise is not mistaken for a
     * shortfall.
     */
    private function assertSplitTotalsOneHundred(Validator $validator): void
    {
        $direct = (int) round(((float) $this->input('direct_recruitment_pct')) * 100);
        $promotion = (int) round(((float) $this->input('promotion_recruitment_pct')) * 100);

        if ($direct + $promotion === 10000) {
            return;
        }

        $total = number_format(($direct + $promotion) / 100, 2);
        $shortfall = ($direct + $promotion) < 10000 ? 'short by ' : 'over by ';
        $difference = number_format(abs(10000 - ($direct + $promotion)) / 100, 2);

        $validator->errors()->add('promotion_recruitment_pct', sprintf(
            'Direct Recruitment %% and Promotion %% must total exactly 100%%. They currently total %s%% — %s%s%%.',
            $total,
            $shortfall,
            $difference,
        ));
    }

    /**
     * Refuse a second *draft* for an exercise that already has an active approved
     * Pre-DPC (item 9), unless this is an amendment of it.
     *
     * Item 9 governs what may be *active*, and the schema enforces that. This is
     * the earlier, kinder half: telling an officer at the form that Higher
     * Education already has an approved 2026 exercise for this pair, rather than
     * letting them fill in a whole proposal that will supersede a Government Order
     * they may not have known about.
     *
     * An amendment is exempt because superseding is precisely its purpose — it
     * arrives carrying `parent_version_id`, which a fresh draft never does.
     */
    private function assertExerciseIsNotAlreadyActive(Validator $validator): void
    {
        $editing = $this->route('preDpc');

        if ($editing instanceof PreDpcProposal && $editing->parent_version_id !== null) {
            return;
        }

        $existing = PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $this->user()?->department_id)
            // The Promotion From Post left this key on 2026-08-25: an order names
            // several, so it cannot identify the exercise.
            ->where('promotion_to_post_id', (int) $this->input('promotion_to_post_id'))
            ->where('dpc_year', (int) $this->input('dpc_year'))
            ->when($editing instanceof PreDpcProposal, fn ($query) => $query->whereKeyNot($editing->id))
            ->first();

        if ($existing === null) {
            return;
        }

        $validator->errors()->add('promotion_to_post_id', sprintf(
            'An approved Pre-DPC (%s) is already in force for %s. Open it and press Amend to supersede it — '
            .'a new proposal for the same exercise would replace it without a record of why.',
            $existing->pre_dpc_number,
            $existing->exerciseLabel(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        /*
         | The source messages are keyed with a wildcard.
         |
         | They used to be keyed on the bare `promotion_from_post_id`, which stopped
         | matching anything the moment the field became `promotion_sources.0.…` —
         | the rule still refused the save, but with the framework's generic wording
         | instead of the sentence written for it. A message that silently stops
         | firing is worse than no custom message: nothing fails, and the screen
         | quietly gets worse.
         */
        return [
            'dpc_year.min' => __('predpc.validation.year_before_earliest', ['year' => (int) config('dpc.earliest_dpc_year', 2019)]),
            'promotion_sources.required' => 'Name at least one Promotion From Post. An order that names none sanctions nobody\'s promotion.',
            'promotion_sources.*.promotion_from_post_id.required' => 'Choose the Promotion From Post — the post officers are promoted out of.',
            'promotion_sources.*.promotion_from_post_id.different' => 'A post cannot be promoted into itself. Choose a different Promotion From Post.',
            'promotion_sources.*.promotion_from_post_id.exists' => 'Select a live post of your own department from the Department Post master.',
            'promotion_sources.*.promotion_percentage.required' => 'Enter this post\'s share of the promotion quota.',
            // Required since 2026-09-06, so it needs a sentence of its own: the
            // framework's default names the array field, which is not what the
            // officer is looking at.
            'promotion_sources.*.qualification_ids.required' => __('predpc.validation.qualification_required'),
            'promotion_sources.*.qualification_ids.min' => __('predpc.validation.qualification_required'),
            'promotion_sources.*.qualification_ids.*.exists' => 'Select live qualifications from the Qualification master.',
            'promotion_to_post_id.required' => 'Choose the Promotion To Post — the post officers are promoted into.',
            'promotion_to_post_id.exists' => 'Select a live post of your own department from the Department Post master.',
            'dpc_year.required' => 'Every Pre-DPC belongs to a DPC Year. Enter it.',
            'dpc_year.max' => 'A Pre-DPC can be prepared for the current or the coming year, not beyond.',
        ];
    }
}
