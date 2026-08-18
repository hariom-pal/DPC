<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A minimum educational qualification a recruitment rule may require.
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property string|null $description
 * @property MasterStatus $status
 */
class Qualification extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_hi',
        'description',
    ];

    /**
     * The qualifications a rule may be *newly* set to.
     *
     * Deliberately narrower than "not deleted": an Inactive or Archived
     * qualification stays attached to the rules that already reference it —
     * withdrawing a qualification does not retrospectively invalidate the
     * Government Orders that required it — but it must not be selectable on a
     * new or amended rule.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', MasterStatus::Active->value);
    }

    public function isSelectable(): bool
    {
        return $this->status === MasterStatus::Active;
    }

    /**
     * @return HasMany<RecruitmentRuleFeeder, $this>
     */
    public function recruitmentRuleFeeders(): HasMany
    {
        return $this->hasMany(RecruitmentRuleFeeder::class);
    }
}
