<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the security log (Phase 1, 2026-10-27). Append-only.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property int|null $actor_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'event', 'actor_id', 'ip_address', 'user_agent', 'context', 'occurred_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('The security log is append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('The security log is append-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
