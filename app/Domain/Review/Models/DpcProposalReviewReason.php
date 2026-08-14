<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ReviewReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One ground on which a Review DPC was raised (PPT S35, S37) — M8 Phase 1.
 *
 * S37 renders the six S35 reasons as **checkboxes**: several routinely apply at
 * once. M4 prepared a singular `review_reason_id` and is frozen, so the
 * many-side lives here.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $review_reason_id
 * @property string|null $detail
 */
class DpcProposalReviewReason extends Model implements Auditable
{
    use AuditableTrait;

    protected $table = 'dpc_proposal_review_reasons';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'review_reason_id',
        'detail',
    ];

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<ReviewReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReviewReason::class, 'review_reason_id');
    }
}
