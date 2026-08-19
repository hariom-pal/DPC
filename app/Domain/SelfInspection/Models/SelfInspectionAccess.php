<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Models;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded attempt to open the Self-Inspection screen (M10, PPT S49–S51).
 *
 * Append-only by intent: nothing updates or deletes a row here. It is evidence,
 * and the record of a *failed* attempt is the more valuable half.
 *
 * @property int $id
 * @property string $uuid
 * @property string $claimed_name
 * @property bool $matched
 * @property int|null $dpc_evaluation_row_id
 * @property int|null $dpc_proposal_id
 * @property string $auth_driver
 * @property list<string> $factors_required
 * @property string $action
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class SelfInspectionAccess extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'claimed_name',
        'matched',
        'dpc_evaluation_row_id',
        'dpc_proposal_id',
        'auth_driver',
        'factors_required',
        'action',
        'ip_address',
        'user_agent',
    ];

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
        return [
            'matched' => 'boolean',
            'factors_required' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }
}
