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
 * One recorded sanctioned strength for a Vacancy Year (1 January – 31 December), with
 * its sanctioning order and document.
 *
 * Entered by the CGPSC Administrator for each year, because a sanctioned strength can
 * change from year to year on its own order — it is not read from the Department Post
 * Mapping. Append-only: a correction is a new row.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vacancy_position_id
 * @property int $sanctioned_total
 * @property string $order_number
 * @property Carbon $order_date
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $remarks
 * @property string|null $correction_reason
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 */
class VacancyPositionSanctionedStrength extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'vacancy_position_id',
        'sanctioned_total',
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
            'sanctioned_total' => 'integer',
            'order_date' => 'date',
            'size_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
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
