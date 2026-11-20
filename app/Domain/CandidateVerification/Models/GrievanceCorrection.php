<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use App\Domain\CandidateVerification\Enums\VerificationSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A candidate's section the AH opened for the AO to correct, and the AO's return of it.
 *
 * @property int $id
 * @property int $dpc_grievance_id
 * @property int $dpc_evaluation_row_id
 * @property VerificationSection $section
 * @property string $instructions
 * @property int|null $assigned_by
 * @property Carbon $assigned_at
 * @property string|null $ao_remarks
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string $status
 * @property-read Grievance $grievance
 */
class GrievanceCorrection extends Model
{
    public const OPEN = 'open';

    public const SUBMITTED = 'submitted';

    protected $table = 'dpc_grievance_corrections';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['section' => VerificationSection::class, 'assigned_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /** @return BelongsTo<Grievance, $this> */
    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'dpc_grievance_id');
    }
}
