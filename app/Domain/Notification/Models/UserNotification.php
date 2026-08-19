<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing an actor needs to know, waiting for them in the application.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $workflow
 * @property string $event
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property Carbon|null $read_at
 * @property Carbon $occurred_at
 */
class UserNotification extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'workflow',
        'event',
        'title',
        'body',
        'url',
        'read_at',
        'occurred_at',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
