<?php

declare(strict_types=1);

namespace App\Domain\Shared\Workflow;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only ledger of workflow transitions.
 *
 * Each transition carries its own UUID (architect directive): a transition is
 * a legal record, and referring to one by sequential id in an export or a
 * document discloses how many exist and invites guessing neighbours.
 *
 * @property int $id
 * @property string $uuid
 * @property string $workflow
 * @property string $event
 * @property string|null $from_state
 * @property string $to_state
 * @property int|null $actor_id
 * @property string|null $remarks
 * @property int|null $signature_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 */
class WorkflowTransition extends Model
{
    use HasUuids;

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
     * @var list<string>
     */
    protected $fillable = [
        'workflow',
        'subject_type',
        'subject_id',
        'event',
        'from_state',
        'to_state',
        'actor_id',
        'remarks',
        'signature_id',
        'ip_address',
        'user_agent',
        'meta',
        'occurred_at',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
