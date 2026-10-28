<?php

declare(strict_types=1);

namespace App\Domain\Department\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time invitation to become a department's Department Admin (business
 * decision, 2026-10-26).
 *
 * The token itself is never stored — only its SHA-256 — so it exists only in the
 * e-mail sent to the invited address. Pending means: not accepted, not revoked,
 * not expired.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property int|null $invited_by
 * @property bool $mobile_otp_waived
 * @property Carbon|null $last_sent_at
 * @property int $send_count
 * @property string|null $delivery_error
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property-read Department $department
 */
class DepartmentInvitation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['token_hash'];

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

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'mobile_otp_waived' => 'boolean',
            'send_count' => 'integer',
        ];
    }
}
