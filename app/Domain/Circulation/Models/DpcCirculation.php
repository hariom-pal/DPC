<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A circulation note — परिभ्रमण टीप (PPT S45, S47; C3/C7) — M9 Phase 1.
 *
 * **Its own aggregate (AD-24).** This model does not extend `DpcProposal`,
 * shares no base with it, and is not selected by a type discriminator. It
 * *references* the origin DPC and never writes to it.
 *
 * **It performs no DPC execution (AD-23).** Nothing here reaches the scoring,
 * eligibility, zone or proceeding services, and nothing here recalculates. The
 * origin's approved result is the promotion's basis; this record adds a
 * decision and, in Phase 3, a signature.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property int $origin_dpc_proposal_id
 * @property int $origin_dpc_proceeding_id
 * @property string|null $origin_dpc_code
 * @property Carbon|null $origin_dpc_date
 * @property string|null $circulation_code
 * @property Carbon $proposal_date
 * @property CirculationStatus $status
 * @property int|null $raised_by
 * @property string|null $raised_by_name
 * @property Carbon|null $raised_at
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property int|null $approval_signature_id
 * @property Carbon|null $returned_at
 * @property string|null $return_remarks
 * @property Carbon|null $cancelled_at
 * @property int|null $consented_by
 * @property string|null $consented_by_name
 * @property Carbon|null $consented_at
 * @property int|null $signature_id
 */
class DpcCirculation extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'origin_dpc_proposal_id',
        'origin_dpc_proceeding_id',
        'origin_dpc_code',
        'origin_dpc_date',
        'circulation_code',
        'proposal_date',
        'status',
        'raised_by',
        'raised_by_name',
        'raised_at',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'approval_signature_id',
        'returned_at',
        'return_remarks',
        'cancelled_at',
        // Phase 2 — the Commission's side.
        'consented_by',
        'consented_by_name',
        'consented_at',
        'signature_id',
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
            'origin_dpc_date' => 'date',
            'proposal_date' => 'date',
            'status' => CirculationStatus::class,
            'raised_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * The origin DPC's UUID must reach the audit trail, which keys on a
     * sequential id — the same reason M7's holds carry their proceeding's.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([$this->uuid, $this->originProposal?->uuid]));
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The DPC being circulated. **Read-only from here** — a circulation never
     * writes to `dpc_proposals` (AD-24).
     *
     * @return BelongsTo<DpcProposal, $this>
     */
    public function originProposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'origin_dpc_proposal_id');
    }

    /**
     * The concluded sitting the holds belong to. Read-only.
     *
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function originProceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'origin_dpc_proceeding_id');
    }

    /**
     * @return HasMany<DpcCirculationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DpcCirculationItem::class, 'dpc_circulation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function consentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consented_by');
    }

    /**
     * Every officer on the note has been decided — the note may be signed.
     */
    public function isFullyDecided(): bool
    {
        return $this->items()->whereNull('decision')->doesntExist();
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'circulation_uuid' => $this->uuid,
            'circulation_code' => $this->circulation_code,
            'origin_dpc_code' => $this->origin_dpc_code,
            'origin_dpc_proposal_id' => $this->origin_dpc_proposal_id,
            'proposal_date' => $this->proposal_date->toDateString(),
            'status' => $this->status->value,
        ];
    }
}
