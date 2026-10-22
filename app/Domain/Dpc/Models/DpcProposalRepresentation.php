<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A representation, complaint or letter received about a DPC, uploaded with its
 * summary (Coder Instructions rule 4.4). The department's undertaking that none of
 * them affects the DPC is the rule 1.1 checklist item `representations`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property string|null $received_from
 * @property Carbon|null $received_on
 * @property string $summary
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 */
class DpcProposalRepresentation extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id', 'received_from', 'received_on', 'summary',
        'disk', 'path', 'original_name', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Addressed by UUID in a URL, never by its sequential id (AD-8). */
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
            'received_on' => 'date',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
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
}
