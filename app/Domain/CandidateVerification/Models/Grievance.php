<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\GrievanceDecision;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\VerificationSection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate's grievance on one section, its supporting PDF and the AH's decision.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_verification_participant_id
 * @property int $dpc_verification_cycle_id
 * @property VerificationSection $section
 * @property string $category
 * @property int|null $related_year
 * @property string $remark
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property Carbon $undertaking_accepted_at
 * @property GrievanceStatus $status
 * @property Carbon|null $submitted_at
 * @property GrievanceDecision|null $decision
 * @property string|null $resolution_remark
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property-read VerificationParticipant $participant
 * @property-read VerificationCycle $cycle
 */
class Grievance extends Model
{
    use HasUuids;

    protected $table = 'dpc_grievances';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'section' => VerificationSection::class,
            'status' => GrievanceStatus::class,
            'decision' => GrievanceDecision::class,
            'related_year' => 'integer',
            'size_bytes' => 'integer',
            'undertaking_accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<VerificationParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(VerificationParticipant::class, 'dpc_verification_participant_id');
    }

    /** @return BelongsTo<VerificationCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(VerificationCycle::class, 'dpc_verification_cycle_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return HasMany<GrievanceCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(GrievanceCorrection::class, 'dpc_grievance_id')->orderBy('id');
    }

    /**
     * What the candidate reads (2026-11-24): "under review" until the AH has resolved
     * it — an AO's correction still with the AH is not yet done — then the decision.
     */
    public function candidateStatusLabel(): string
    {
        if ($this->status === GrievanceStatus::Resolved) {
            return (string) __('candidate.grievance.candidate_status.'.($this->decision === GrievanceDecision::NotUpheld ? 'resolved_not_upheld' : 'resolved_upheld'));
        }

        return (string) __('candidate.grievance.candidate_status.'.$this->status->value);
    }
}
