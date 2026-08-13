<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Enums\SignatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * DPC committee member (PPT S15). References an existing `User` wherever
 * possible; `user_id` is null for an external member (manual entry).
 * `signature_status`/`signed_at`/`signature_reference` are unused schema
 * preparation for CSC DigiSign — no signing logic yet.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $mobile
 * @property string|null $email
 * @property CommitteeCapacity $capacity
 * @property int $sort_order
 * @property SignatureStatus|null $signature_status
 * @property Carbon|null $signed_at
 * @property string|null $signature_reference
 */
class DpcCommitteeMember extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'user_id',
        'name',
        'designation',
        'mobile',
        'email',
        'capacity',
        'sort_order',
        'signature_status',
        'signed_at',
        'signature_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => CommitteeCapacity::class,
            'sort_order' => 'integer',
            'signature_status' => SignatureStatus::class,
            'signed_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }
}
