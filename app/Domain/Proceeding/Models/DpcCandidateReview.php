<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A committee member's Agree on one candidate's record at the sitting: given for every
 * candidate at once by the member's one Agree on the DPC (2026-11-25; until then an OK
 * pressed candidate by candidate, 2026-11-18), and turned into a Disagree, with a remark
 * for the DPC Chairman, on any candidate the member does not agree on.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $dpc_evaluation_row_id
 * @property int $user_id
 * @property Carbon $reviewed_at
 * @property Carbon|null $disagreed_at the member disagrees on this candidate; never withdrawn
 * @property string|null $disagree_remarks why, for the DPC Chairman
 * @property Carbon|null $invalidated_at no longer counts: the record changed after it
 * @property string|null $invalidation_reason correction | resubmission
 */
class DpcCandidateReview extends Model
{
    protected $fillable = ['dpc_proposal_id', 'dpc_evaluation_row_id', 'user_id', 'reviewed_at', 'disagreed_at', 'disagree_remarks', 'invalidated_at', 'invalidation_reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'disagreed_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
