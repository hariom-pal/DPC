<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A departmental enquiry, charge-sheet, show-cause or suspension an officer may have
 * pending (Coder Instructions rule 2.2), and what it does to the DPC's finding on
 * that officer: kept in a sealed cover with the post held, or no effect.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property string $effect
 * @property bool $requires_document
 * @property int $sort_order
 */
class SealedCoverReason extends Model implements Auditable
{
    use HasUuids;
    use IsMaster;

    public const EFFECT_SEALED_COVER = 'sealed_cover';

    public const EFFECT_NONE = 'no_effect';

    /**
     * @return list<string>
     */
    public static function effects(): array
    {
        return [self::EFFECT_SEALED_COVER, self::EFFECT_NONE];
    }

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
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_hi',
        'effect',
        'requires_document',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_document' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<SealedCoverReason>  $query
     * @return Builder<SealedCoverReason>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query
            ->where('status', MasterStatus::Active->value)
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    public function sealsTheCover(): bool
    {
        return $this->effect === self::EFFECT_SEALED_COVER;
    }

    public function label(): string
    {
        if (app()->getLocale() === 'hi' && $this->name_hi !== '') {
            return $this->name_hi;
        }

        return $this->name_en;
    }
}
