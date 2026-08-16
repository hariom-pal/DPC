<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Models;

use App\Domain\Proceeding\Enums\HoldReason;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One held officer covered by a circulation note — M9 Phase 1.
 *
 * **This is where the department's resolution evidence lives**, and it lives
 * here precisely so it never lands on the frozen chart. The supplied record is
 * recorded *against the hold*, not written back onto `dpc_evaluation_rows`.
 *
 * **Nothing here releases the hold.** `hold_released_at` is written in Phase 2,
 * at signature and not before (AQ-17), and it mirrors the hold's own
 * `released_at` so an item remains readable on its own.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_circulation_id
 * @property int $dpc_proceeding_hold_id
 * @property int $sn
 * @property string $officer_name
 * @property HoldReason $reason_code
 * @property string $resolution_detail
 * @property string|null $resolution_reference
 * @property Carbon $resolution_received_on
 * @property string|null $decision
 * @property Carbon|null $hold_released_at
 */
class DpcCirculationItem extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_circulation_id',
        'dpc_proceeding_hold_id',
        'sn',
        'officer_name',
        'reason_code',
        'resolution_detail',
        'resolution_reference',
        'resolution_received_on',
        'decision',
        'decided_by',
        'decided_by_name',
        'decided_at',
        'hold_released_at',
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
            'resolution_received_on' => 'date',
            'decided_at' => 'datetime',
            'hold_released_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([$this->uuid, $this->circulation?->uuid]));
    }

    /**
     * @return BelongsTo<DpcCirculation, $this>
     */
    public function circulation(): BelongsTo
    {
        return $this->belongsTo(DpcCirculation::class, 'dpc_circulation_id');
    }

    /**
     * The hold this item resolves. **Read-only in Phase 1.**
     *
     * @return BelongsTo<DpcProceedingHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(DpcProceedingHold::class, 'dpc_proceeding_hold_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'item_uuid' => $this->uuid,
            'hold_uuid' => $this->hold?->uuid,
            'sn' => $this->sn,
            'officer_name' => $this->officer_name,
            'reason_code' => $this->reason_code->value,
            'reason' => $this->reason_code->label(),
            'resolution_reference' => $this->resolution_reference,
            'resolution_received_on' => $this->resolution_received_on->toDateString(),
        ];
    }
}
