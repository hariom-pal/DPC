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
 * The Integrity (सत्यनिष्ठा) answer of one CR year (business decision, 2026-11-14):
 * chosen beside the year's grade, and whether the officer is Fit or Unfit on it is
 * the Administrator's, set on the option.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property bool $fit
 * @property int $sort_order
 */
class IntegrityOption extends Model implements Auditable
{
    use HasUuids;
    use IsMaster;

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
        'fit',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fit' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<IntegrityOption>  $query
     * @return Builder<IntegrityOption>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query
            ->where('status', MasterStatus::Active->value)
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    public function label(): string
    {
        if (app()->getLocale() === 'hi' && $this->name_hi !== '') {
            return $this->name_hi;
        }

        return $this->name_en;
    }
}
