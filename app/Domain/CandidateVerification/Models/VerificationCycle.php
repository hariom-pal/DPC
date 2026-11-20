<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A DPC's candidate verification (2026-11-19): opened by the AH's approval, closed by
 * its deadline. One per DPC.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property CycleStatus $status
 * @property Carbon $approved_at
 * @property int|null $started_by
 * @property int $duration_days
 * @property Carbon $deadline_at
 * @property Carbon|null $closed_at
 * @property string|null $routed_to
 * @property Carbon|null $routed_at
 * @property int $participant_count
 * @property int $grievance_count
 * @property-read DpcProposal $proposal
 */
class VerificationCycle extends Model
{
    use HasUuids;

    protected $table = 'dpc_verification_cycles';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'approved_at' => 'datetime',
            'deadline_at' => 'datetime',
            'closed_at' => 'datetime',
            'routed_at' => 'datetime',
            'duration_days' => 'integer',
            'participant_count' => 'integer',
            'grievance_count' => 'integer',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return BelongsTo<DpcProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /** @return HasMany<VerificationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(VerificationParticipant::class, 'dpc_verification_cycle_id');
    }

    /** @return HasMany<Grievance, $this> */
    public function grievances(): HasMany
    {
        return $this->hasMany(Grievance::class, 'dpc_verification_cycle_id');
    }

    /** Whether candidates may still submit: open and not past its deadline. */
    public function acceptsSubmissions(?Carbon $now = null): bool
    {
        return $this->status === CycleStatus::Open && ($now ?? now())->lte($this->deadline_at);
    }

    /** Whole days left, never below zero. */
    public function daysLeft(?Carbon $now = null): int
    {
        $now ??= now();

        return $now->gte($this->deadline_at) ? 0 : (int) ceil($now->diffInSeconds($this->deadline_at) / 86400);
    }
}
