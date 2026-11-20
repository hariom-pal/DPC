<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Signature\Models\Signature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One officer of the DPC's Seniority List in its verification.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_verification_cycle_id
 * @property int $dpc_evaluation_row_id
 * @property ParticipantStatus $status
 * @property Carbon|null $first_viewed_at
 * @property Carbon|null $submitted_at
 * @property int|null $signature_id
 * @property array<string, mixed>|null $submitted_snapshot
 * @property string|null $snapshot_sha256
 * @property-read VerificationCycle $cycle
 * @property-read DpcEvaluationRow $row
 */
class VerificationParticipant extends Model
{
    use HasUuids;

    protected $table = 'dpc_verification_participants';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'first_viewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'submitted_snapshot' => 'array',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return BelongsTo<VerificationCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(VerificationCycle::class, 'dpc_verification_cycle_id');
    }

    /** @return BelongsTo<DpcEvaluationRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /** @return HasMany<VerificationDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(VerificationDecision::class, 'dpc_verification_participant_id');
    }

    /** @return HasMany<Grievance, $this> */
    public function grievances(): HasMany
    {
        return $this->hasMany(Grievance::class, 'dpc_verification_participant_id');
    }

    /** @return HasMany<VerificationSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(VerificationSnapshot::class, 'dpc_verification_participant_id')->orderBy('version');
    }

    /** @return BelongsTo<Signature, $this> */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }

    public function hasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
