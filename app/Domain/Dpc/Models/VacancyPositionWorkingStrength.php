<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One recorded working strength as on 1 January, with its order and document.
 * Append-only: a correction is a new row.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vacancy_position_id
 * @property int $working_total
 * @property int|null $working_promotion
 * @property int|null $working_direct
 * @property int|null $working_deputation
 * @property bool $exceeds_sanctioned_acknowledged
 * @property string|null $order_number
 * @property Carbon|null $order_date
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $remarks
 * @property string|null $correction_reason
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 */
class VacancyPositionWorkingStrength extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'vacancy_position_id',
        'working_total',
        'working_promotion',
        'working_direct',
        'working_deputation',
        'exceeds_sanctioned_acknowledged',
        'order_number',
        'order_date',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'remarks',
        'correction_reason',
        'recorded_by',
        'recorded_at',
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
            'working_total' => 'integer',
            'working_promotion' => 'integer',
            'working_direct' => 'integer',
            'working_deputation' => 'integer',
            'exceeds_sanctioned_acknowledged' => 'boolean',
            'order_date' => 'date',
            'size_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Whether the split by occupancy is recorded, which the quota arithmetic needs.
     */
    public function hasQuotaSplit(): bool
    {
        return $this->working_promotion !== null
            && $this->working_direct !== null
            && $this->working_deputation !== null;
    }

    /**
     * @return BelongsTo<VacancyPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(VacancyPosition::class, 'vacancy_position_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
