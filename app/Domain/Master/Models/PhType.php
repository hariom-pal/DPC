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
 * The PH Type master (2026-11-05): the kind of disability a PH officer has, by the
 * Government of India's codes for persons with benchmark disabilities (OL = One
 * Leg, OAL = One Arm and One Leg, …), each in its RPwD Act category (a) to (e).
 *
 * A withdrawn type stays on the officers already recorded with it and is not
 * offered on a new one (`selectable`).
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property string|null $category
 * @property int $sort_order
 * @property MasterStatus $status
 */
class PhType extends Model implements Auditable
{
    use HasUuids;
    use IsMaster;

    /** The RPwD Act's categories of benchmark disability. */
    public const CATEGORIES = ['a', 'b', 'c', 'd', 'e'];

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
        'category',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The types an officer may be recorded with now.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query
            ->where('status', MasterStatus::Active->value)
            ->orderBy('sort_order')
            ->orderBy('code');
    }

    /** "OL — One Leg", in the reader's language. */
    public function label(): string
    {
        $name = app()->getLocale() === 'hi' && $this->name_hi !== '' ? $this->name_hi : $this->name_en;

        return $this->code.' — '.$name;
    }
}
