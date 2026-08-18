<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Requests;

use App\Domain\Master\Models\Cadre;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SavePreDpcProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Derive the names from the master references the screen posts.
     *
     * **This is what keeps the endpoint backward-compatible.** From 2026-08-18
     * the form sends `cadre_id` and `feeder_cadre_id` and no longer sends a
     * typed post name at all — but `post_name` and `feeder_name` are still
     * `required` below, are still stored, and are still what an older caller
     * sends. Filling them in from the chosen cadre means:
     *
     *  - the screen posts references only, as business asked;
     *  - a caller that posts only text — the demo seeder, `PreDpcWorkflowTest`,
     *    any existing API client — is completely unaffected;
     *  - the stored row always carries both, so nothing that reads `post_name`
     *    has to change.
     *
     * A name is only ever *derived*, never overwritten: if a caller sends both,
     * theirs is kept. The master is the better answer, but silently replacing a
     * value somebody supplied is not this method's decision to make.
     */
    protected function prepareForValidation(): void
    {
        $posts = $this->input('posts');

        if (! is_array($posts)) {
            return;
        }

        $names = Cadre::query()
            ->where('department_id', $this->user()?->department_id)
            ->pluck('name', 'id');

        foreach ($posts as $p => $post) {
            if (! is_array($post)) {
                continue;
            }

            $posts[$p]['post_name'] = $this->nameFor($post, 'cadre_id', 'post_name', $names);

            foreach (($post['feeders'] ?? []) as $f => $feeder) {
                if (is_array($feeder)) {
                    $posts[$p]['feeders'][$f]['feeder_name'] = $this->nameFor($feeder, 'feeder_cadre_id', 'feeder_name', $names);
                }
            }
        }

        $this->merge(['posts' => $posts]);
    }

    /**
     * The supplied name if there is one, otherwise the chosen cadre's name.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, string>  $names
     */
    private function nameFor(array $row, string $idKey, string $nameKey, Collection $names): mixed
    {
        if (trim((string) ($row[$nameKey] ?? '')) !== '') {
            return $row[$nameKey];
        }

        $id = $row[$idKey] ?? null;

        return $id !== null && $names->has((int) $id)
            ? $names[(int) $id]
            : ($row[$nameKey] ?? null);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Cadres are department-scoped, so a proposal may only name posts from
        // the department filing it. Scoping the `exists` rule rather than
        // checking afterwards means a request naming another department's cadre
        // fails as "not a valid selection" instead of leaking that it exists.
        $inDepartment = fn () => Rule::exists('cadres', 'id')
            ->where('department_id', $this->user()?->department_id)
            ->where('status', MasterStatus::Active->value);

        return [
            'government_order_number' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'posts' => ['array'],

            // The post this rule is for, chosen from the Cadre master.
            // Nullable, because a caller that posts only `post_name` — the shape
            // this endpoint has always accepted — must keep working.
            'posts.*.cadre_id' => ['nullable', $inDepartment()],
            'posts.*.post_name' => ['required', 'string', 'max:255'],

            'posts.*.class_level' => ['required', new Enum(ServiceClass::class)],
            'posts.*.pay_scale_id' => ['nullable', Rule::exists('pay_scales', 'id')],
            'posts.*.direct_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'posts.*.promotion_recruitment_pct' => ['required', 'numeric', 'between:0,100'],
            'posts.*.feeders' => ['array'],

            'posts.*.feeders.*.feeder_cadre_id' => ['nullable', $inDepartment()],
            'posts.*.feeders.*.feeder_name' => ['required', 'string', 'max:255'],
            'posts.*.feeders.*.promotion_percentage' => ['required', 'numeric', 'between:0,100'],
            'posts.*.feeders.*.min_experience_years' => ['required', 'integer', 'min:0', 'max:60'],

            // Chosen from the Qualification master. Optional — a Schedule-IV
            // feeder may impose no qualification beyond holding the feeder post
            // — but when given it must be a live row: Active, and not
            // soft-deleted. `whereNull('deleted_at')` is not redundant:
            // `Rule::exists` runs on the query builder, so the SoftDeletes
            // global scope never applies and a trashed row would otherwise
            // validate, its `status` column still reading 'active'.
            'posts.*.feeders.*.qualification_id' => [
                'nullable',
                Rule::exists('qualifications', 'id')
                    ->where('status', MasterStatus::Active->value)
                    ->whereNull('deleted_at'),
            ],
            'posts.*.feeders.*.min_qualification' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'posts.*.cadre_id.exists' => 'Select a post from your department\'s Cadre master.',
            'posts.*.feeders.*.feeder_cadre_id.exists' => 'Select a feeder cadre from your department\'s Cadre master.',
            'posts.*.feeders.*.qualification_id.exists' => 'Select a qualification that is Active in the Qualification master.',
            'posts.*.post_name.required' => 'Choose the post this entry is for.',
            'posts.*.feeders.*.feeder_name.required' => 'Choose the feeder cadre.',
        ];
    }

    /**
     * Each post's Direct Recruitment % + Promotion % must total exactly 100%.
     *
     * The same rule the Recruitment Rule master enforces, applied at the screen
     * that originates the numbers (PPT S9 — every worked row reads 0 / 100).
     *
     * **It is here because approving a Pre-DPC projects these posts into the
     * Recruitment Rule master.** Validating only in the master would let a
     * department approve a Pre-DPC that silently writes a rule the admin screen
     * then refuses to re-save — the same drift that `normaliseShares()` had to
     * be introduced to prevent for the feeder Promotion Share column. One rule,
     * enforced at both ends, is the only version of this that cannot rot.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateRecruitmentSplit($validator);
            $this->validateCadreReferences($validator);
        });
    }

    /**
     * The reference checks that are properties of the set, not of one field.
     *
     * All three exist for the same reason the split rule above does: **an
     * approved Pre-DPC is projected into the Recruitment Rule master**, whose
     * own screen already refuses a self-feeder, a duplicate feeder and a
     * cross-department feeder — and whose `recruitment_rule_feeders` table
     * carries a unique index on (rule, feeder cadre). Without these, a
     * department could approve a Pre-DPC whose projection either writes a rule
     * the admin screen can never re-save, or fails on a database constraint at
     * the worst possible moment: inside the approval transaction, after the
     * proposal has been digitally signed.
     *
     * They apply only where a cadre reference was actually given, so a
     * text-only caller is untouched.
     */
    private function validateCadreReferences(Validator $validator): void
    {
        $posts = $this->input('posts');

        if (! is_array($posts)) {
            return;
        }

        $seenPosts = [];

        foreach ($posts as $p => $post) {
            if (! is_array($post)) {
                continue;
            }

            $postCadre = $post['cadre_id'] ?? null;

            if ($postCadre !== null && $postCadre !== '') {
                if (isset($seenPosts[(int) $postCadre])) {
                    $validator->errors()->add(
                        "posts.{$p}.cadre_id",
                        'This post is already listed in this proposal. One entry per post.',
                    );
                }

                $seenPosts[(int) $postCadre] = true;
            }

            $seenFeeders = [];

            foreach (($post['feeders'] ?? []) as $f => $feeder) {
                $feederCadre = is_array($feeder) ? ($feeder['feeder_cadre_id'] ?? null) : null;

                if ($feederCadre === null || $feederCadre === '') {
                    continue;
                }

                $key = "posts.{$p}.feeders.{$f}.feeder_cadre_id";

                // A post cannot be promoted from itself.
                if ($postCadre !== null && (int) $feederCadre === (int) $postCadre) {
                    $validator->errors()->add($key, 'A post cannot be its own feeder cadre.');
                }

                if (isset($seenFeeders[(int) $feederCadre])) {
                    $validator->errors()->add($key, 'This feeder cadre is already listed for this post. Remove the duplicate row.');
                }

                $seenFeeders[(int) $feederCadre] = true;
            }
        }
    }

    /**
     * Each post's Direct Recruitment % + Promotion % must total exactly 100%.
     */
    private function validateRecruitmentSplit(Validator $validator): void
    {
        $posts = $this->input('posts');

        if (! is_array($posts)) {
            return;
        }

        foreach ($posts as $index => $post) {
            if (! is_array($post)) {
                continue;
            }

            $direct = $post['direct_recruitment_pct'] ?? null;
            $promotion = $post['promotion_recruitment_pct'] ?? null;

            // Missing or non-numeric is already reported by the field rules.
            if (! is_numeric($direct) || ! is_numeric($promotion)) {
                continue;
            }

            $total = (float) $direct + (float) $promotion;

            if (abs($total - 100.0) < 0.01) {
                continue;
            }

            // The post is named, not numbered: these errors surface in a summary
            // list at the top of the form, well away from the card that caused
            // them, and "post 3" is no help on a nine-post ladder the user is
            // scrolling through.
            $name = is_string($post['post_name'] ?? null) && trim($post['post_name']) !== ''
                ? trim($post['post_name'])
                : 'Post '.((int) $index + 1);

            $validator->errors()->add(
                "posts.{$index}.direct_recruitment_pct",
                sprintf(
                    '%s: Direct Recruitment %% and Promotion %% must total exactly 100%%. They currently total %s%%.',
                    $name,
                    rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.'),
                ),
            );
        }
    }
}
