<?php

declare(strict_types=1);

namespace App\Domain\Signature\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One verified eSign attempt (2026-10-28): who tried, for which action, how it
 * ended, and the name the provider asserted against the portal name of the time.
 *
 * @property int $id
 * @property string $signable_type
 * @property int $signable_id
 * @property int $user_id
 * @property string $action
 * @property string $outcome
 * @property string|null $asserted_name
 * @property string|null $portal_name
 * @property Carbon $created_at
 * @property-read User $user
 */
class SignatureAttempt extends Model
{
    public const OUTCOME_VERIFIED = 'verified';

    /**
     * @var list<string>
     */
    protected $fillable = ['signable_type', 'signable_id', 'user_id', 'action', 'outcome', 'asserted_name', 'portal_name'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An eSign attempt is history once written.');
        });

        static::deleting(function (): void {
            throw new LogicException('An eSign attempt is never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['asserted_name' => 'encrypted', 'portal_name' => 'encrypted'];
    }

    public function failed(): bool
    {
        return $this->outcome !== self::OUTCOME_VERIFIED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
