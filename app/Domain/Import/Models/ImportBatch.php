<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportBatchStatus;
use App\Domain\Import\Enums\ImportTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One uploaded spreadsheet, staged for review before it touches a proposal
 * (PPT S16–S18) — M4 Phase 6.
 *
 * Audited: an import is a bulk write to legally significant data, so who
 * uploaded what and who confirmed it belongs in the audit trail alongside
 * the Activity Timeline entry the commit produces.
 *
 * Retained permanently by architect ruling (2026-08-13): the original
 * spreadsheet, its CSV error report, and its import log (`import_rows`) are
 * never deleted, and `file_hash` (SHA-256) fixes the identity of the uploaded
 * file for duplicate detection, evidence, and integrity checking.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property ImportTemplate $template
 * @property ImportBatchStatus $status
 * @property string $original_filename
 * @property string $stored_path
 * @property string|null $file_hash
 * @property string|null $error_report_path
 * @property int $total_rows
 * @property int $imported_count
 * @property int $rejected_count
 * @property int|null $uploaded_by
 * @property int|null $committed_by
 * @property Carbon|null $previewed_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $cancelled_at
 */
class ImportBatch extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'template',
        'status',
        'original_filename',
        'stored_path',
        'file_hash',
        'error_report_path',
        'total_rows',
        'imported_count',
        'rejected_count',
        'uploaded_by',
        'committed_by',
        'previewed_at',
        'committed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => ImportTemplate::class,
            'status' => ImportBatchStatus::class,
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'rejected_count' => 'integer',
            'previewed_at' => 'datetime',
            'committed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function validRows(): HasMany
    {
        return $this->rows()->where('is_valid', true);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rejectedRows(): HasMany
    {
        return $this->rows()->where('is_valid', false);
    }
}
