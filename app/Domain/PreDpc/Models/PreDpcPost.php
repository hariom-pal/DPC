<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\PayScale;
use App\Enums\ServiceClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $pre_dpc_proposal_id
 * @property string $post_name
 * @property ServiceClass $class_level
 * @property int|null $pay_scale_id
 * @property int|null $cadre_id
 * @property numeric-string $direct_recruitment_pct
 * @property numeric-string $promotion_recruitment_pct
 * @property int $sort_order
 */
class PreDpcPost extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pre_dpc_proposal_id',
        'post_name',
        'class_level',
        'pay_scale_id',
        'cadre_id',
        'direct_recruitment_pct',
        'promotion_recruitment_pct',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_level' => ServiceClass::class,
            'direct_recruitment_pct' => 'decimal:2',
            'promotion_recruitment_pct' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayScale, $this>
     */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /**
     * @return BelongsTo<Cadre, $this>
     */
    public function cadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class);
    }

    /**
     * @return HasMany<PreDpcPostFeeder, $this>
     */
    public function feeders(): HasMany
    {
        return $this->hasMany(PreDpcPostFeeder::class)->orderBy('sort_order');
    }
}
