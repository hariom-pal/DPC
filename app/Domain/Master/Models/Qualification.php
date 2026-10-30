<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\PreDpc\Models\PreDpcPromotionSource;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Dropdown order: by name, with "NA" last (MoM of 2026-10-29).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInDropdownOrder(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN code = ? THEN 1 ELSE 0 END', [QualificationCatalogue::NOT_APPLICABLE])->orderBy('name_en');
    }

    public function isSelectable(): bool
    {
        return $this->status === MasterStatus::Active;
    }

    /**
     * The recruitment-rule promotion sources that accept this qualification.
     *
     * **Many-to-many since 2026-08-26** (change register item 11): a promotion
     * source may prescribe several acceptable qualifications — *BE or BTech or
     * MCA* — so the single `recruitment_rule_sources.qualification_id` it used to
     * hang off is gone, and this reads the join table instead.
     *
     * @return BelongsToMany<RecruitmentRuleSource, $this>
     */
    public function recruitmentRuleSources(): BelongsToMany
    {
        return $this->belongsToMany(
            RecruitmentRuleSource::class,
            'rr_source_qualifications',
            'qualification_id',
            'recruitment_rule_source_id',
        );
    }

    /**
     * The Pre-DPC promotion sources that accept this qualification.
     *
     * @return BelongsToMany<PreDpcPromotionSource, $this>
     */
    public function preDpcPromotionSources(): BelongsToMany
    {
        return $this->belongsToMany(
            PreDpcPromotionSource::class,
            'pre_dpc_source_qualifications',
            'qualification_id',
            'pre_dpc_promotion_source_id',
        );
    }
}
