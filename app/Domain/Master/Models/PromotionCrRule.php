<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The CR, Loyalty and Property Declaration requirements for promotion into a class
 * from a class, from a DPC year until a newer rule. Never edited: a change is a new
 * rule, so the rule a past DPC followed stays readable.
 *
 * @property int $id
 * @property string $uuid
 * @property string $to_class
 * @property string $from_class
 * @property int $effective_from_year
 * @property int $cr_years
 * @property int $loyalty_years
 * @property int $property_years
 * @property string $qualifying_average
 * @property int|null $qualifying_total
 * @property string $tribal_bonus_basis `year` (each tribal CR year) or `officer` (one bonus, the PPT's)
 * @property bool $tribal_bonus_skips_extremes no bonus for a year at the highest or the lowest grade
 * @property int|null $super_seat_grade
 * @property int $tribal_bonus
 * @property string|null $remarks
 * @property int|null $recorded_by
 * @property Carbon $created_at
 */
class PromotionCrRule extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'to_class', 'from_class', 'effective_from_year', 'cr_years', 'loyalty_years', 'property_years',
        'qualifying_average', 'qualifying_total', 'super_seat_grade', 'tribal_bonus', 'tribal_bonus_basis', 'tribal_bonus_skips_extremes', 'remarks', 'recorded_by',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from_year' => 'integer',
            'cr_years' => 'integer',
            'loyalty_years' => 'integer',
            'property_years' => 'integer',
            'qualifying_average' => 'decimal:2',
            'qualifying_total' => 'integer',
            'tribal_bonus_skips_extremes' => 'boolean',
            'super_seat_grade' => 'integer',
            'tribal_bonus' => 'integer',
        ];
    }

    /**
     * @return HasMany<PromotionCrRuleYear, $this>
     */
    public function years(): HasMany
    {
        return $this->hasMany(PromotionCrRuleYear::class)->orderBy('years_before');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** How many year rows the rule carries: the longest of the three requirements. */
    public function span(): int
    {
        return max($this->cr_years, $this->loyalty_years, $this->property_years);
    }
}
