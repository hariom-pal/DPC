<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recruitment_rule_id
 * @property int $feeder_cadre_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
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
            'sort_order' => 'integer',
        ];
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
}
