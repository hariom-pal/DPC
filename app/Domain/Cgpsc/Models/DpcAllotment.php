<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One allotment of a DPC to a Chairman or Member (PPT S28) — M6 Phase 1.
 *
 * **Append-only.** Re-allotting creates a new row and marks this one
 * superseded; nothing is edited in place (architect directive). The only
 * update a row ever accepts is being marked superseded, enforced here at the
 * model layer so the guarantee survives any caller.
 *
 * A proposal's *current* allotment is its newest row with no successor. The
 * CGPSC-side state is derived from that, never duplicated onto `dpc_proposals`
 * — M4 is frozen, and two sources of truth can disagree.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int $allotted_to
 * @property int|null $allotted_by
 * @property Carbon $allotted_at
 * @property Carbon|null $default_dpc_date
 * @property Carbon|null $accepted_at
 * @property AllotmentStatus $status
 * @property int|null $superseded_by_id
 * @property string|null $reallotment_reason
 */
class DpcAllotment extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'allotted_to',
        'allotted_by',
        'allotted_at',
        'default_dpc_date',
        'status',
        'accepted_at',
        'superseded_by_id',
        'reallotment_reason',
    ];

    /**
     * The public identifier is the UUID; the auto-increment id stays internal.
     *
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
            'accepted_at' => 'datetime',
            'default_dpc_date' => 'date',
            'status' => AllotmentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allotment): void {
            // Being superseded is the one permitted change. Everything else
            // about an allotment is history the moment it is written.
            $changed = array_keys($allotment->getDirty());
            // Lifecycle progression only: acceptance, scheduling, and being
            // superseded. Who it was allotted to, when, and by whom are
            // history the moment they are written.
            $permitted = ['status', 'accepted_at', 'superseded_by_id', 'updated_at'];

            if (array_diff($changed, $permitted) !== []) {
                throw new RuntimeException(
                    'An allotment is a historical record and cannot be edited. Re-allot the DPC instead; '
                    .'allotment history is never overwritten.'
                );
            }
        });

        static::deleting(function (self $allotment): void {
            throw new RuntimeException('Allotments are permanent records and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allottee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allotted_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allottedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allotted_by');
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
