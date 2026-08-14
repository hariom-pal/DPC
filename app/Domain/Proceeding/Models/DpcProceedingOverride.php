<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Proceeding\Enums\CorrectableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One Commission correction recorded during a proceeding (PPT S33 EDIT /
 * TRIBAL) — M7 Phase 1 (model only; Phase 2 records into it).
 *
 * **This model is why M4 stays frozen.** The obvious implementation of S33's
 * `EDIT` button is to update the evaluation row. That is also the one that
 * destroys the evidential model: a corrected row would silently contradict the
 * signed M4 approval document that attested to it, and the department's
 * submission would become indistinguishable from the Commission's correction.
 * So a correction is recorded here, and `dpc_evaluation_rows` is read-only.
 *
 * **Append-only.** A later correction to the same field supersedes this row
 * rather than overwriting it; being superseded is the only change a row ever
 * accepts.
 *
 * **Replayable** (architect Directive 2). Every row carries UUID, previous
 * value, new value, reason, actor and timestamp, which is exactly the set
 * needed to reconstruct the correction history — and the old-beside-new
 * display — from timeline records alone, without walking `superseded_by_id`
 * through business tables. `toTimelineProperties()` is that contract, kept on
 * the model so the recording service and any future replaying subscriber
 * cannot drift apart.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_id
 * @property int $sequence
 * @property int|null $dpc_evaluation_row_id
 * @property int $sn
 * @property string $officer_name
 * @property string $field
 * @property int|null $year
 * @property string|null $previous_value
 * @property string|null $submitted_value
 * @property string|null $new_value
 * @property string $reason
 * @property int|null $applied_by
 * @property string $applied_by_name
 * @property Carbon $applied_at
 * @property int|null $superseded_by_id
 */
class DpcProceedingOverride extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proceeding_id',
        'sequence',
        'dpc_evaluation_row_id',
        'sn',
        'officer_name',
        'field',
        'year',
        'previous_value',
        'submitted_value',
        'new_value',
        'reason',
        'applied_by',
        'applied_by_name',
        'applied_at',
        'superseded_by_id',
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
            'sequence' => 'integer',
            'sn' => 'integer',
            'year' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $override): void {
            // Being superseded is the one permitted change. What was corrected,
            // from what, to what, why and by whom is history the moment it is
            // written — a correction that could be edited would evidence
            // nothing.
            $permitted = ['superseded_by_id', 'updated_at'];

            if (array_diff(array_keys($override->getDirty()), $permitted) !== []) {
                throw new RuntimeException(
                    'A correction is a historical record and cannot be edited. Record a further correction '
                    .'instead; correction history is never overwritten.'
                );
            }
        });

        static::deleting(function (self $override): void {
            throw new RuntimeException('Corrections are permanent records and cannot be deleted.');
        });
    }

    /**
     * The **proceeding** UUID must appear in the audit trail, not only the
     * override's own (architect Directive 3). Both are tagged: without the
     * proceeding UUID an audit export of corrections could not be tied to the
     * sitting they were made in.
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([
            $this->uuid,
            $this->proceeding?->uuid,
        ]));
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * The M4 row this corrects. Read only — nothing in M7 writes through it.
     *
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Corrections that have not been superseded — the ones in force.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    /**
     * Directive 2 — repository filtering. No screen consumes these yet; they
     * exist so the audit-trail UI (M11), a Review DPC (M8) and any export can
     * ask the question directly instead of filtering in PHP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOfficer(Builder $query, int $sn): Builder
    {
        return $query->where('sn', $sn);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForField(Builder $query, CorrectableField|string $field): Builder
    {
        return $query->where('field', $field instanceof CorrectableField ? $field->value : $field);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForYear(Builder $query, ?int $year): Builder
    {
        return $year === null
            ? $query->whereNull('year')
            : $query->where('year', $year);
    }

    /**
     * Everything a timeline entry needs to replay this correction without
     * reading a business table (architect Directive 2).
     *
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'override_uuid' => $this->uuid,
            'correction_sequence' => $this->sequence,
            'sn' => $this->sn,
            'officer_name' => $this->officer_name,
            'field' => $this->field,
            'year' => $this->year,
            'previous_value' => $this->previous_value,
            'new_value' => $this->new_value,
            // Directive 3. `effective_value` is what is in force for this cell
            // once this event has been applied. It equals `new_value` for a
            // correction and for a withdrawal alike — stating it explicitly
            // means a consumer never has to know that a withdrawal's new value
            // *is* the restoration, which is exactly the inference a replaying
            // integration would get wrong.
            'effective_value' => $this->new_value,
            // The department's own entry, carried on every event. After a
            // chain of corrections a later event's `previous_value` is what
            // the previous correction made it, not what the department sent —
            // so without this the old-beside-new display could not be
            // reproduced from the timeline alone, which is what Directive 2
            // requires of it.
            'submitted_value' => $this->submitted_value,
            'reason' => $this->reason,
            'applied_by_id' => $this->applied_by,
            'applied_by_name' => $this->applied_by_name,
            'applied_at' => $this->applied_at->toIso8601String(),
        ];
    }
}
