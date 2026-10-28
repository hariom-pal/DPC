<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One identity-verification outcome (2026-10-27). Append-only: the one permitted
 * change is its revocation. `returned_identity` (what DigiLocker returned) is
 * encrypted at rest and shown to the CGPSC Super Admin only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $method
 * @property string $outcome
 * @property list<string>|null $mismatched_fields
 * @property array<string, string>|null $returned_identity
 * @property string|null $provider_reference
 * @property string|null $reason
 * @property int|null $decided_by
 * @property string|null $document_disk
 * @property string|null $document_path
 * @property string|null $document_name
 * @property string|null $document_sha256
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoke_reason
 * @property Carbon $created_at
 * @property-read User|null $decidedBy
 * @property-read User|null $revokedBy
 */
class IdentityVerification extends Model
{
    use HasUuids;

    public const OUTCOME_VERIFIED = 'verified';

    public const OUTCOME_MISMATCH = 'mismatch';

    public const OUTCOME_TECHNICAL_FAILURE = 'technical_failure';

    public const OUTCOME_CONSENT_DENIED = 'consent_denied';

    public const OUTCOME_NOT_AADHAAR_VERIFIED = 'not_aadhaar_verified';

    public const OUTCOME_RECORD_INCOMPLETE = 'record_incomplete';

    public const OUTCOME_MANUAL = 'manually_verified';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'method', 'outcome', 'mismatched_fields', 'returned_identity', 'provider_reference', 'reason',
        'decided_by', 'document_disk', 'document_path', 'document_name', 'document_sha256', 'ip_address',
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
            'mismatched_fields' => 'array',
            'returned_identity' => 'encrypted:array',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['revoked_at', 'revoked_by', 'revoke_reason', 'updated_at']) !== []) {
                throw new LogicException('An identity verification is history once written.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('An identity verification is never deleted.');
        });
    }

    public function grantsIdentity(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_VERIFIED, self::OUTCOME_MANUAL], true) && $this->revoked_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
