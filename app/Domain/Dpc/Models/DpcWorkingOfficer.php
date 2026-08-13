<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Master\Models\ReservationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Officer currently working on the target post under consideration (PPT
 * S16). A proposal-time snapshot (architect directive, 2026-08-13): every
 * field is a plain column, deliberately with no live FK to any
 * employee/HRMS record — there is no such source in this system, and even
 * if one is added later, a historical proposal must never change because
 * upstream data changed after the fact.
 *
 * Carries the standard ten-field employee snapshot (architect directive,
 * 2026-08-13). `date_of_posting_on_post` is this table's "Date of Posting
 * on Current Post" — kept under its Phase 3 name because renaming an
 * approved column is a breaking change.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $sn
 * @property string|null $employee_number
 * @property string $name
 * @property Carbon $dob
 * @property string|null $qualification
 * @property string|null $current_post
 * @property string|null $feeder_post
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_posting_on_post
 * @property Carbon|null $date_of_posting_on_feeder_post
 * @property int $reservation_category_id
 * @property int|null $roster_position
 * @property Carbon|null $deputation_date
 */
class DpcWorkingOfficer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'sn',
        'employee_number',
        'name',
        'dob',
        'qualification',
        'current_post',
        'feeder_post',
        'date_of_joining_service',
        'date_of_posting_on_post',
        'date_of_posting_on_feeder_post',
        'reservation_category_id',
        'roster_position',
        'deputation_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sn' => 'integer',
            'dob' => 'date',
            'date_of_joining_service' => 'date',
            'date_of_posting_on_post' => 'date',
            'date_of_posting_on_feeder_post' => 'date',
            'roster_position' => 'integer',
            'deputation_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<ReservationCategory, $this>
     */
    public function reservationCategory(): BelongsTo
    {
        return $this->belongsTo(ReservationCategory::class);
    }
}
