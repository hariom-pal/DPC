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
 * One field-level change to an officer's identity details (2026-10-27).
 * Append-only; old and new values are encrypted at rest.
 *
 * `stage`: `pre_verification` (Department Admin or Super Admin, before DigiLocker
 * succeeded) or `verified_correction` (Super Admin only, with a supporting
 * document; revokes the verification).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $stage
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $reason
 * @property int|null $corrected_by
 * @property string|null $document_disk
 * @property string|null $document_path
 * @property string|null $document_name
 * @property string|null $document_sha256
 * @property Carbon $created_at
 * @property-read User|null $correctedBy
 */
class IdentityCorrection extends Model
{
    use HasUuids;

    public const STAGE_PRE_VERIFICATION = 'pre_verification';

    public const STAGE_VERIFIED = 'verified_correction';

    /**
     * The department's account handed to a new Department Admin by the CGPSC's
     * fresh invitation (2026-10-28): the old identity is kept here, the new one
     * comes from DigiLocker.
     */
    public const STAGE_HANDOVER = 'handover';

    /**
     * An AO's or AH's details corrected by the Department Admin after an eSign
     * whose name did not match (2026-10-28).
     */
    public const STAGE_ESIGN = 'esign_correction';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'stage', 'field', 'old_value', 'new_value', 'reason', 'corrected_by',
        'document_disk', 'document_path', 'document_name', 'document_sha256',
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
        return ['old_value' => 'encrypted', 'new_value' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An identity correction is history once written.');
        });

        static::deleting(function (): void {
            throw new LogicException('An identity correction is never deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
