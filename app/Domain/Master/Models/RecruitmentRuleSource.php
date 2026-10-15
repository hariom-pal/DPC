<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Enums\ExperienceUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One promotion from post on a recruitment rule, with the conditions attached to
 * promotion from it.
 *
 * **Qualifications are plural since 2026-08-26** (change register item 11). A
 * Schedule-IV entry for a technical post accepts *any* of a list of degrees — BE or
 * BTech or MTech or MCA — and the single `qualification_id` this used to carry
 * could record only one of them. They now live in `rr_source_qualifications`;
 * see {@see qualifications()}.
 *
 * `min_qualification` is the free-text column that predates both and is retained as
 * the legacy record of what the Government Order said. Read the pair through
 * {@see qualificationLabel()}, never `min_qualification` directly — see that method
 * for why.
 *
 * @property int $id
 * @property int $recruitment_rule_id
 * @property int $promotion_from_post_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
 * @property ExperienceUnit $min_experience_unit
 * @property string|null $min_qualification
 * @property int $sort_order
 * @property-read Collection<int, Qualification> $qualifications
 */
class RecruitmentRuleSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'recruitment_rule_id',
        'promotion_from_post_id',
        'promotion_percentage',
        'min_experience_years',
        'min_experience_unit',
        'min_qualification',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promotion_percentage' => 'decimal:2',
            'min_experience_years' => 'integer',
            'min_experience_unit' => ExperienceUnit::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * The minimum experience in **months**, whatever unit it was published in.
     *
     * Added by the change register of 2026-08-27, item 12, which makes qualifying
     * experience a gate rather than a label. A gate has to compare two numbers, and
     * `min_experience_years` alone is not a number you can compare — the same `18`
     * means eighteen years or eighteen months depending on `min_experience_unit`, and
     * the enum exists precisely because *"rounding those up to a year changes who is
     * eligible"*.
     *
     * Months rather than years is the finer unit, so nothing is lost: a rule
     * published as 18 months stays 18 months instead of becoming one year or two.
     *
     * Null when the rule requires no experience, so the gate is skipped rather than
     * comparing against zero.
     */
    public function minExperienceInMonths(): ?int
    {
        if ($this->min_experience_years <= 0) {
            return null;
        }

        return $this->min_experience_unit === ExperienceUnit::Months
            ? $this->min_experience_years
            : $this->min_experience_years * 12;
    }

    /**
     * "5 Years" — the value and its unit together, which is the only form that
     * means anything on screen. Returns null when no experience is required, so
     * a caller can show a dash rather than "0 Years".
     */
    public function experienceLabel(): ?string
    {
        if ($this->min_experience_years <= 0) {
            return null;
        }

        return $this->min_experience_years.' '.$this->min_experience_unit->labelFor($this->min_experience_years);
    }

    /**
     * @return BelongsTo<RecruitmentRule, $this>
     */
    public function recruitmentRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRule::class);
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionFromPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_from_post_id');
    }

    /**
     * Every qualification this source accepts (change register item 11).
     *
     * Ordered by `sort_order` so the list reads the way it was entered — a rule
     * that lists *BE, BTech, MCA* should not show them alphabetically, because the
     * order in a Government Order is sometimes the order of preference.
     *
     * @return BelongsToMany<Qualification, $this>
     */
    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Qualification::class,
            'rr_source_qualifications',
            'recruitment_rule_source_id',
            'qualification_id',
        )->withPivot('sort_order')->orderBy('rr_source_qualifications.sort_order');
    }

    /**
     * What to display as the minimum qualification.
     *
     * Prefers the master rows and falls back to the legacy free text, so a rule
     * whose historic text matched no master entry still shows what the Government
     * Order required instead of showing nothing.
     *
     * **Several qualifications read as alternatives, not requirements** — "BE or
     * BTech or MCA" — because that is what a Schedule-IV list of acceptable
     * degrees means. Joining them with "and" would state the opposite of the rule.
     */
    public function qualificationLabel(): ?string
    {
        $names = $this->qualifications->pluck('name_en')->all();

        if ($names !== []) {
            return implode(' / ', $names);
        }

        return $this->min_qualification;
    }
}
