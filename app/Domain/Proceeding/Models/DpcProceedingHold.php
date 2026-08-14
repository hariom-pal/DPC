<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Proceeding\Enums\HoldReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * A held promotion (`CIR`) — M7 Phase 4.
 *
 * **Append-only.** Being released is the one permitted change, and M9 writes
 * it. Why an officer was held, when, and by whom is history the moment it is
 * recorded — a hold that could be edited would evidence nothing, and a hold is
 * the reason a promotion did not take effect.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_id
 * @property int|null $dpc_evaluation_row_id
 * @property int $sn
 * @property string $officer_name
 * @property HoldReason $reason_code
 * @property string|null $detail
 * @property bool $is_automatic
 * @property int|null $recorded_by
 * @property string $recorded_by_name
 * @property Carbon $recorded_at
 * @property Carbon|null $released_at
 */
class DpcProceedingHold extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proceeding_id',
        'dpc_evaluation_row_id',
        'sn',
        'officer_name',
        'reason_code',
        'detail',
        'is_automatic',
        'recorded_by',
        'recorded_by_name',
        'recorded_at',
        'released_at',
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
            'sn' => 'integer',
            'reason_code' => HoldReason::class,
            'is_automatic' => 'boolean',
            'recorded_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $hold): void {
            $permitted = ['released_at', 'updated_at'];

            if (array_diff(array_keys($hold->getDirty()), $permitted) !== []) {
                throw new RuntimeException(
                    'A hold is a historical record and cannot be edited. Record a further hold, or release '
                    .'this one; hold history is never overwritten.'
                );
            }
        });

        static::deleting(function (self $hold): void {
            throw new RuntimeException('Holds are permanent records and cannot be deleted.');
        });
    }

    /**
     * The proceeding UUID must reach the audit trail, which keys on a
     * sequential id (architect Directive 3, Phase 2).
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([$this->uuid, $this->proceeding?->uuid]));
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Holds still in force — the promotion is still reserved.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'hold_uuid' => $this->uuid,
            'sn' => $this->sn,
            'officer_name' => $this->officer_name,
            'reason_code' => $this->reason_code->value,
            'reason' => $this->reason_code->label(),
            'detail' => $this->detail,
            'is_automatic' => $this->is_automatic,
            'recorded_by_id' => $this->recorded_by,
            'recorded_by_name' => $this->recorded_by_name,
            'recorded_at' => $this->recorded_at->toIso8601String(),
        ];
    }
}
