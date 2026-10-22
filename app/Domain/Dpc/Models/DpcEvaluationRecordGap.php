<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\RecordReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A justified, evidenced absence of one mandatory record (change register of
 * 2026-08-27, items 14, 15, 17 and 19).
 *
 * > *Whenever any mandatory CR or Loyalty record is unavailable, AO shall upload
 * > Reason and Supporting PDF. Candidate shall continue in the DPC. Candidate
 * > Status = CIR.*
 *
 * **A gap is a statement, not an absence.** Before this register a missing CR was
 * simply a year with no grade, and the engine scored what it found. Now the officer
 * has to say *why* it is missing and attach the evidence — and having said so, the
 * candidate stays in the exercise with the promotion held rather than dropping out
 * of it.
 *
 * **One kind column rather than two tables.** A missing CR and a missing Loyalty
 * Certificate carry identical information, are validated identically, and produce
 * the identical consequence — item 19 names them in one sentence. Two tables would
 * be two places to add the next field to, and one of them would be missed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_evaluation_row_id
 * @property string $kind
 * @property int $year
 * @property int $record_reason_id
 * @property string|null $remarks
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property int|null $recorded_by
 */
class DpcEvaluationRecordGap extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * A missing Confidential Report (item 14).
     */
    public const KIND_CR = 'cr';

    /**
     * A missing Loyalty Certificate (item 15).
     */
    public const KIND_LOYALTY = 'loyalty';

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [self::KIND_CR, self::KIND_LOYALTY];
    }

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
     * @var list<string>
     */
    protected $fillable = [
        'dpc_evaluation_row_id',
        'kind',
        'year',
        'record_reason_id',
        'remarks',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'recorded_by',
        'second_disk',
        'second_path',
        'second_original_name',
        'second_size_bytes',
        'second_sha256',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<RecordReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(RecordReason::class, 'record_reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Whether the supporting PDF is actually attached.
     *
     * The file columns are nullable so the row and its upload are written in one
     * transaction rather than two; the request makes the PDF mandatory. So a gap
     * with no document is a fault to report, not a state to design for — and this is
     * how a caller asks.
     */
    public function hasDocument(): bool
    {
        return $this->path !== null && $this->disk !== null;
    }

    public function hasSecondDocument(): bool
    {
        return $this->second_path !== null && $this->second_disk !== null;
    }

    /**
     * Justified: the reason and its document — and, where the reason needs two (rule
     * 3.2, a suspension's suspension and reinstatement orders), both.
     */
    public function isJustified(): bool
    {
        return $this->hasDocument() && ($this->reason?->requiresSecondDocument() !== true || $this->hasSecondDocument());
    }

    /**
     * Whether the stored file still matches the hash taken when it was received.
     *
     * A justification whose evidence cannot be shown to be the file that was
     * uploaded is not a justification, which is why the hash is recorded at all.
     */
    public function isIntact(): bool
    {
        if (! $this->hasDocument()) {
            return false;
        }

        $disk = Storage::disk((string) $this->disk);

        if (! $disk->exists((string) $this->path)) {
            return false;
        }

        return hash('sha256', (string) $disk->get((string) $this->path)) === $this->sha256;
    }

    /**
     * How this gap reads on a screen — "CR 2023 — Record Not Available".
     */
    public function citation(): string
    {
        return sprintf(
            '%s %d — %s%s',
            $this->kind === self::KIND_CR ? 'CR' : 'Loyalty',
            $this->year,
            $this->reason?->label() ?? '—',
            $this->remarks === null || $this->remarks === '' ? '' : ' ('.$this->remarks.')',
        );
    }
}
