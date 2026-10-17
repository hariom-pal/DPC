<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One year of a promotion CR rule. `years_before` 1 is the year just before the DPC
 * year (for a 2026 DPC, 2025).
 *
 * @property int $id
 * @property int $promotion_cr_rule_id
 * @property int $years_before
 * @property int|null $cr_min_marks
 * @property bool $cr_mandatory
 * @property bool $loyalty_mandatory
 * @property bool $property_mandatory
 */
class PromotionCrRuleYear extends Model
{
    protected $fillable = ['years_before', 'cr_min_marks', 'cr_mandatory', 'loyalty_mandatory', 'property_mandatory'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'years_before' => 'integer',
            'cr_min_marks' => 'integer',
            'cr_mandatory' => 'boolean',
            'loyalty_mandatory' => 'boolean',
            'property_mandatory' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PromotionCrRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PromotionCrRule::class, 'promotion_cr_rule_id');
    }
}
