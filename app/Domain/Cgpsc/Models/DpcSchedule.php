<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * A DPC date and time set by the allotted Member (PPT S30) — M6 Phase 2.
 *
 * **Append-only, like allotments** (architect directive). Re-scheduling —
 * Phase 3 — writes a new row and marks this one superseded rather than editing
 * the date, because S30 shows the previous date and time beside the new one,
 * which is only possible if the old row survives.
 *
 * The model permits exactly one change: being superseded. Anything else, and
 * deletion outright, is refused at the model layer so the guarantee survives
 * any caller.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_allotment_id
 * @property Carbon $dpc_date
 * @property string $dpc_time
 * @property int|null $scheduled_by
 * @property Carbon $scheduled_at
 * @property int|null $superseded_by_id
 * @property string|null $reschedule_reason
 */
class DpcSchedule extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_allotment_id',
        'dpc_date',
        'dpc_time',
        'scheduled_by',
        'scheduled_at',
        'superseded_by_id',
        'reschedule_reason',
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
            'dpc_date' => 'date',
            'scheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $schedule): void {
            $changed = array_keys($schedule->getDirty());
            $permitted = ['superseded_by_id', 'reschedule_reason', 'updated_at'];

            if (array_diff($changed, $permitted) !== []) {
                throw new RuntimeException(
                    'A schedule is a historical record and cannot be edited. Re-schedule the DPC instead; '
                    .'scheduling history is never overwritten.'
                );
            }
        });

        static::deleting(function (self $schedule): void {
            throw new RuntimeException('Schedules are permanent records and cannot be deleted.');
        });
    }

    /**
     * The scheduled moment, for display and for future calendar subscribers.
     */
    public function scheduledFor(): Carbon
    {
        return Carbon::parse($this->dpc_date->toDateString().' '.$this->dpc_time);
    }

    /**
     * @return BelongsTo<DpcAllotment, $this>
     */
    public function allotment(): BelongsTo
    {
        return $this->belongsTo(DpcAllotment::class, 'dpc_allotment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }
}
