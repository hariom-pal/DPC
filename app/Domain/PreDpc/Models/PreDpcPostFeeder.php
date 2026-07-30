<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Master\Models\Cadre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pre_dpc_post_id
 * @property string $feeder_name
 * @property int|null $feeder_cadre_id
 * @property numeric-string $promotion_percentage
 * @property int $min_experience_years
 * @property string|null $min_qualification
 * @property int $sort_order
 */
class PreDpcPostFeeder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pre_dpc_post_id',
        'feeder_name',
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
     * @return BelongsTo<Cadre, $this>
     */
    public function feederCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'feeder_cadre_id');
    }
}
