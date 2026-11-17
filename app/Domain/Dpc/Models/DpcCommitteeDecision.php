<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DPC Committee member's decision on a DPC after evaluating it (MoM of 16
 * November 2026, points 2 and 3): agreed, or returned to the AO with the sections
 * to correct, each with its remark.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int|null $user_id
 * @property string $member_name
 * @property string $decision
 * @property string|null $remarks
 * @property array<string, string>|null $sections section => remark
 * @property Carbon $decided_at
 * @property Carbon|null $resubmitted_at
 */
class DpcCommitteeDecision extends Model
{
    use HasUuids;

    public const AGREED = 'agreed';

    public const RETURNED = 'returned';

    protected $fillable = ['dpc_proposal_id', 'user_id', 'member_name', 'decision', 'remarks', 'sections', 'decided_at', 'resubmitted_at'];

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
        return ['sections' => 'array', 'decided_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }
}
