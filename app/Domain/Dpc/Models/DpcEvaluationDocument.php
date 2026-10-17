<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One employee-wise document uploaded by the Authorized Officer inside a DPC
 * proposal.
 *
 * Rows are never deleted by the application. A replacement writes a new row and
 * stamps the old one `superseded_at` / `superseded_by_id`, so every version that was
 * ever uploaded — and who uploaded it, when — stays on record.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int|null $dpc_evaluation_row_id
 * @property string|null $employee_number
 * @property EvaluationDocumentKind $kind
 * @property int|null $year
 * @property Carbon|null $undertaking_confirmed_at
 * @property string|null $remarks
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 * @property Carbon|null $superseded_at
 * @property int|null $superseded_by_id
 * @property-read User|null $uploader
 * @property-read DpcEvaluationRow|null $row
 */
class DpcEvaluationDocument extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'dpc_proposal_id',
        'dpc_evaluation_row_id',
        'employee_number',
        'kind',
        'year',
        'undertaking_confirmed_at',
        'remarks',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'uploaded_at',
        'superseded_at',
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

    protected function casts(): array
    {
        return [
            'kind' => EvaluationDocumentKind::class,
            'year' => 'integer',
            'size_bytes' => 'integer',
            'undertaking_confirmed_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
