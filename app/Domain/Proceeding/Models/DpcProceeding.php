<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Signature\Models\Signature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One DPC sitting (PPT S31–S34) — M7 Phase 1.
 *
 * The proceeding is the Commission's own record of conducting the DPC. It
 * reads the department's approved chart and never writes to it: M4 is frozen,
 * and corrections are append-only overrides (architect ruling, AQ-1).
 *
 * **Frozen once terminal.** While `in_proceeding` the record accepts the
 * lifecycle columns Phase 3 will populate; once approved or cancelled it
 * accepts nothing at all. Correcting a concluded DPC is a Review DPC (M8),
 * which creates a new proposal rather than editing this one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int|null $dpc_schedule_id
 * @property DpcProceedingStatus $status
 * @property int|null $conducted_by
 * @property string $conducted_by_name
 * @property int|null $closed_by
 * @property string|null $closed_by_name
 * @property Carbon|null $closed_at
 * @property string $proposal_version
 * @property Carbon $opened_at
 * @property int|null $promotion_as_on_year
 * @property int|null $latest_calculation_run_id
 * @property int|null $calculated_through_sequence
 * @property string|null $calculation_engine_version
 * @property int|null $final_calculation_run_id
 * @property Carbon|null $approved_at
 * @property int|null $signature_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 */
class DpcProceeding extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'dpc_schedule_id',
        'status',
        'conducted_by',
        'conducted_by_name',
        'closed_by',
        'closed_by_name',
        'closed_at',
        'proposal_version',
        'opened_at',
        'promotion_as_on_year',
        'latest_calculation_run_id',
        'calculated_through_sequence',
        'calculation_engine_version',
        'final_calculation_run_id',
        'approved_at',
        'signature_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * The public identifier is the UUID (architect Directive 1); the
     * auto-increment id stays internal.
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
            'status' => DpcProceedingStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'promotion_as_on_year' => 'integer',
            'calculated_through_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $proceeding): void {
            // Once concluded, nothing changes — not by any role, including an
            // administrator. The original values are read from the model's
            // state before this update, so a record that was already terminal
            // rejects the write outright.
            $original = $proceeding->getOriginal('status');
            $status = $original instanceof DpcProceedingStatus
                ? $original
                : DpcProceedingStatus::from((string) $original);

            if ($status->isTerminal()) {
                throw new RuntimeException(
                    'This proceeding has concluded and is a permanent record. It cannot be edited; '
                    .'a correction is made through a Review DPC.'
                );
            }

            // While open, only the lifecycle columns may move. Who conducted
            // it, when it opened, and the proposal fingerprint it acted on are
            // history the moment they are written — they are the evidence.
            $permitted = [
                'status', 'promotion_as_on_year',
                'latest_calculation_run_id', 'calculated_through_sequence',
                'calculation_engine_version',
                'final_calculation_run_id',
                'approved_at', 'signature_id', 'cancelled_at', 'cancellation_reason',
                'closed_by', 'closed_by_name', 'closed_at',
                'updated_at',
            ];

            if (array_diff(array_keys($proceeding->getDirty()), $permitted) !== []) {
                throw new RuntimeException(
                    'A proceeding records what happened at the sitting and cannot be rewritten.'
                );
            }
        });

        static::deleting(function (self $proceeding): void {
            throw new RuntimeException('Proceedings are permanent records and cannot be deleted.');
        });
    }

    /**
     * The proceeding UUID must appear in the audit trail, not only in the
     * timeline and the URL (architect Directive 3). The audit row keys on the
     * sequential id, which never leaves the database — without this, an audit
     * export could not be tied back to the proceeding anyone else can see.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return [$this->uuid];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<DpcSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DpcSchedule::class, 'dpc_schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    /**
     * Who ended the sitting (architect Directive 1). Populated by Phase 3.
     *
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * The run currently on screen. Distinct from the approved one: this moves
     * with every recalculation, that never moves once pinned.
     *
     * @return BelongsTo<DpcCalculationRun, $this>
     */
    public function latestCalculationRun(): BelongsTo
    {
        return $this->belongsTo(DpcCalculationRun::class, 'latest_calculation_run_id');
    }

    /**
     * @return BelongsTo<DpcCalculationRun, $this>
     */
    public function finalCalculationRun(): BelongsTo
    {
        return $this->belongsTo(DpcCalculationRun::class, 'final_calculation_run_id');
    }

    /**
     * @return BelongsTo<Signature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }

    /**
     * @return HasMany<DpcProceedingOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(DpcProceedingOverride::class, 'dpc_proceeding_id');
    }

    /**
     * Proceedings that are still open.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DpcProceedingStatus::InProceeding->value);
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
