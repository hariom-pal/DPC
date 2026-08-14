<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One DPC reviews another (PPT S35, S37) — M8 Phase 1.
 *
 * **Permanent and bidirectional** (architect ruling AQ-9). The row carries both
 * foreign keys, so "what reviews this?" and "what does this review?" are the
 * same table read from opposite ends. Nothing is ever deleted and nothing is
 * overwritten: a Review supersedes its original, and the original remains
 * exactly as it was.
 *
 * `chain_uuid` and `review_number` (Directives 1 and 2) place this link in its
 * correction chain. They are not decoration — they turn "show me every
 * correction of this promotion" into one indexed query, and AQ-14's rule that
 * only the latest Review may itself be reviewed into a comparison rather than a
 * recursive walk.
 *
 * @property int $id
 * @property string $uuid
 * @property int $review_dpc_proposal_id
 * @property int $original_dpc_proposal_id
 * @property string $chain_uuid
 * @property string|null $original_proposal_version
 * @property int $review_number
 * @property int $chain_depth
 * @property string $effect
 * @property Carbon|null $superseded_at
 * @property int|null $established_by
 * @property string $established_by_name
 * @property Carbon $established_at
 */
class DpcReviewLink extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'review_dpc_proposal_id',
        'original_dpc_proposal_id',
        'original_proposal_version',
        'chain_uuid',
        'review_number',
        'chain_depth',
        'effect',
        'superseded_at',
        'established_by',
        'established_by_name',
        'established_at',
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
            'review_number' => 'integer',
            'chain_depth' => 'integer',
            'superseded_at' => 'datetime',
            'established_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            // The supersession *taking effect* is the one permitted change,
            // and Phase 2 writes it when the Review's result is approved.
            // Which DPC reviews which, under what chain and at what position,
            // is history the moment it is written.
            $permitted = ['superseded_at', 'updated_at'];

            if (array_diff(array_keys($link->getDirty()), $permitted) !== []) {
                throw new RuntimeException(
                    'A review link is a permanent record and cannot be edited. It records which DPC '
                    .'corrects which, and that never changes.'
                );
            }

            if ($link->getOriginal('superseded_at') !== null && $link->isDirty('superseded_at')) {
                throw new RuntimeException('A superseded DPC cannot be un-superseded.');
            }
        });

        static::deleting(function (self $link): void {
            throw new RuntimeException('Review links are permanent records and cannot be deleted.');
        });
    }

    /**
     * The chain UUID reaches the audit trail, which keys on a sequential id
     * (architect Directive 3, M7 Phase 2 — the same reasoning).
     *
     * @return list<string>
     */
    public function generateTags(): array
    {
        return [$this->uuid, $this->chain_uuid];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'review_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'original_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function establishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'established_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInChain(Builder $query, string $chainUuid): Builder
    {
        return $query->where('chain_uuid', $chainUuid)->orderBy('review_number');
    }

    public function hasTakenEffect(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'review_link_uuid' => $this->uuid,
            'chain_uuid' => $this->chain_uuid,
            'review_number' => $this->review_number,
            'chain_depth' => $this->chain_depth,
            'original_proposal_version' => $this->original_proposal_version,
            'effect' => $this->effect,
            'established_by_id' => $this->established_by,
            'established_by_name' => $this->established_by_name,
            'established_at' => $this->established_at->toIso8601String(),
        ];
    }
}
