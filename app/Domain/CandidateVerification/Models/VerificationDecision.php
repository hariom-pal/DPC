<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use App\Domain\CandidateVerification\Enums\VerificationSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A candidate's decision on one section: agreed, or a grievance.
 *
 * @property int $id
 * @property int $dpc_verification_participant_id
 * @property VerificationSection $section
 * @property string $decision
 * @property Carbon $decided_at
 */
class VerificationDecision extends Model
{
    public const AGREED = 'agreed';

    public const GRIEVANCE = 'grievance';

    protected $table = 'dpc_verification_decisions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['section' => VerificationSection::class, 'decided_at' => 'datetime'];
    }
}
