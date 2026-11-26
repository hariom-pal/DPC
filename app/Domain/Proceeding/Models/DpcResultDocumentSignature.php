<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One committee member's eSign on every result document of a DPC (2026-11-25): one
 * signature on the digest of all their digests.
 *
 * @property int $id
 * @property int $dpc_proceeding_id
 * @property int $user_id
 * @property string $member_name
 * @property string|null $designation
 * @property string $bundle_sha256
 * @property int|null $signature_id
 * @property Carbon $signed_at
 */
class DpcResultDocumentSignature extends Model
{
    /** @var list<string> */
    protected $fillable = ['dpc_proceeding_id', 'user_id', 'member_name', 'designation', 'bundle_sha256', 'signature_id', 'signed_at'];

    /** A member's signature on the documents is never changed or removed (2026-11-26). */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('A signature on the result documents is a permanent record.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('A signature on the result documents is a permanent record.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
