<?php

declare(strict_types=1);

namespace App\Domain\Allotment\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The Authorized Officer and Administrative Head a Pre-DPC or DPC proposal is
 * allotted to (business decision, 2026-10-26).
 *
 * Append-only: the one permitted change is ending the row when the proposal is
 * reassigned. The current allotment is the row with `ended_at` null.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property int|null $pre_dpc_proposal_id
 * @property int|null $dpc_proposal_id
 * @property int $ao_user_id
 * @property int $ah_user_id
 * @property int|null $allotted_by
 * @property Carbon $allotted_at
 * @property string|null $reason
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property-read User $ao
 * @property-read User $ah
 * @property-read User|null $allottedBy
 */
class ProposalAllotment extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'pre_dpc_proposal_id',
        'dpc_proposal_id',
        'ao_user_id',
        'ah_user_id',
        'allotted_by',
        'allotted_at',
        'reason',
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
            'allotted_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allotment): void {
            if (array_diff(array_keys($allotment->getDirty()), ['ended_at', 'ended_by', 'held_at', 'held_reason', 'updated_at']) !== []) {
                throw new LogicException('An allotment is history once written; reassign instead of editing it.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('An allotment is never deleted; reassign instead.');
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ao_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ah_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allottedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allotted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<PreDpcProposal, $this>
     */
    public function preDpcProposal(): BelongsTo
    {
        return $this->belongsTo(PreDpcProposal::class);
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function dpcProposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class);
    }
}
