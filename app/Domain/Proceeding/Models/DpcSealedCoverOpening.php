<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sealed cover opened by the DPC's Chairman (2026-11-05): the competent
 * authority's decision on the officer, its order and evidence, and — for a
 * rejection — what happens to the seat. Written once; never edited.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int $dpc_proceeding_id
 * @property int $dpc_calculation_result_id
 * @property int $sn
 * @property string $officer_name
 * @property string $outcome
 * @property Carbon $opened_on
 * @property string $authority_name
 * @property string $authority_order_number
 * @property Carbon $authority_order_date
 * @property string|null $remarks
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $replacement_status
 * @property int|null $replacement_sn
 * @property string|null $replacement_name
 * @property int $opened_by
 */
class DpcSealedCoverOpening extends Model
{
    use HasUuids;

    public const CLEARED = 'cleared';

    public const REJECTED = 'rejected';

    /** Rejected before 31 December: the next eligible candidate is offered the seat. */
    public const REPLACEMENT_OFFERED = 'offered';

    /** Rejected before 31 December, but nobody is left on the waiting list. */
    public const REPLACEMENT_NONE_AVAILABLE = 'none_available';

    /** Rejected after 31 December: no promotion against the seat through this DPC. */
    public const REPLACEMENT_NOT_AFTER_YEAR = 'not_after_year';

    /** Opened on 31 December itself: the rule is not decided; nothing is reallocated. */
    public const REPLACEMENT_BOUNDARY_UNRESOLVED = 'boundary_unresolved';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id', 'dpc_proceeding_id', 'dpc_calculation_result_id', 'sn', 'officer_name',
        'outcome', 'opened_on', 'authority_name', 'authority_order_number', 'authority_order_date', 'remarks',
        'disk', 'path', 'original_name', 'size_bytes', 'sha256',
        'replacement_status', 'replacement_sn', 'replacement_name', 'opened_by',
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
            'opened_on' => 'date',
            'authority_order_date' => 'date',
            'sn' => 'integer',
            'replacement_sn' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function isCleared(): bool
    {
        return $this->outcome === self::CLEARED;
    }

    /** Whether a promotion order may follow this opening: the cleared officer, or the replacement. */
    public function promotes(): bool
    {
        return $this->isCleared() || $this->replacement_status === self::REPLACEMENT_OFFERED;
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * @return BelongsTo<DpcCalculationResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(DpcCalculationResult::class, 'dpc_calculation_result_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
