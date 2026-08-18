<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Enums\ExperienceUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One feeder cadre on a recruitment rule, with the conditions attached to
 * promotion from it.
 *
 * `qualification_id` is the minimum qualification; `min_qualification` is the
 * free-text column it replaced and is retained as the legacy record of what the
 * Government Order said. Read the pair through {@see qualificationLabel()},
 * never `min_qualification` directly — see that method for why.
 *
 * @property int $id
 * @property int $recruitment_rule_id
 * @property int $feeder_cadre_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
 * @property ExperienceUnit $min_experience_unit
 * @property int|null $qualification_id
 * @property string|null $min_qualification
 * @property int $sort_order
 */
class RecruitmentRuleFeeder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'recruitment_rule_id',
        'feeder_cadre_id',
        'promotion_percentage',
        'min_experience_years',
        'min_experience_unit',
        'qualification_id',
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
     * @return BelongsTo<Cadre, $this>
     */
    public function feederCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'feeder_cadre_id');
    }

    /**
     * @return BelongsTo<Qualification, $this>
     */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    /**
     * What to display as the minimum qualification.
     *
     * Prefers the master row and falls back to the legacy free text, so a rule
     * whose historic text matched no master entry still shows what the
     * Government Order required instead of showing nothing. `withTrashed()`
     * because a soft-deleted qualification must still render on the rules that
     * cite it — the record of what was required does not disappear because the
     * qualification was later withdrawn from the master.
     */
    public function qualificationLabel(): ?string
    {
        if ($this->qualification_id !== null) {
            $qualification = $this->relationLoaded('qualification')
                ? $this->qualification
                : Qualification::withTrashed()->find($this->qualification_id);

            if ($qualification !== null) {
                return $qualification->name_en;
            }
        }

        return $this->min_qualification;
    }
}
