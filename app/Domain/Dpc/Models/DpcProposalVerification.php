<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One Rule 1.1 statement — a confirmation or a withdrawal — about one proposal.
 *
 * Rows are never updated after they are written; see the migration for why the table
 * is append-only.
 *
 * @property Carbon|null $rr_notified_on the latest Recruitment Rule's notification date (rule 4.4)
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int $revision_number
 * @property ProposalVerificationItem $item
 * @property string $status
 * @property string|null $remarks
 * @property array<string, mixed>|null $basis
 * @property string|null $basis_hash
 * @property PreviousDpcBasis|null $previous_dpc_basis
 * @property int|null $previous_dpc_proposal_id
 * @property string|null $previous_dpc_reference
 * @property Carbon|null $previous_dpc_held_on
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property int|null $verified_by
 * @property Carbon $verified_at
 */
class DpcProposalVerification extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'dpc_proposal_id',
        'revision_number',
        'item',
        'status',
        'remarks',
        'basis',
        'basis_hash',
        'previous_dpc_basis',
        'previous_dpc_proposal_id',
        'previous_dpc_reference',
        'previous_dpc_held_on',
        'rr_notified_on',
        'previous_dpc_record_id',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'verified_by',
        'verified_at',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Addressed by UUID in a URL, never by its sequential id (AD-8).
     */
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
            'item' => ProposalVerificationItem::class,
            'previous_dpc_basis' => PreviousDpcBasis::class,
            'basis' => 'array',
            'revision_number' => 'integer',
            'size_bytes' => 'integer',
            'previous_dpc_held_on' => 'date',
            'rr_notified_on' => 'date',
            'verified_at' => 'datetime',
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
     * @return BelongsTo<DpcProposal, $this>
     */
    public function previousDpcProposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'previous_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function hasDocument(): bool
    {
        return $this->disk !== null && $this->path !== null;
    }
}
