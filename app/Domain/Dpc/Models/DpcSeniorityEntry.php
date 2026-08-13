<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Master\Models\ReservationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feeder-post seniority list entry (PPT S17). A proposal-time snapshot —
 * see the migration docblock for why no field here is a live employee
 * reference.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $seniority_number
 * @property string|null $employee_number
 * @property string $name
 * @property Carbon $dob
 * @property string|null $qualification
 * @property string|null $current_post
 * @property string|null $feeder_post
 * @property int $reservation_category_id
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_posting_on_current_post
 * @property Carbon|null $date_of_posting_on_feeder_post
 */
class DpcSeniorityEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'seniority_number',
        'employee_number',
        'name',
        'dob',
        'qualification',
        'current_post',
        'feeder_post',
        'reservation_category_id',
        'date_of_joining_service',
        'date_of_posting_on_current_post',
        'date_of_posting_on_feeder_post',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seniority_number' => 'integer',
            'dob' => 'date',
            'date_of_joining_service' => 'date',
            'date_of_posting_on_current_post' => 'date',
            'date_of_posting_on_feeder_post' => 'date',
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
