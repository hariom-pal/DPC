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
 * @property string|null $mobile
 * @property Carbon $dob
 * @property string|null $qualification
 * @property string|null $promotion_to_post
 * @property string|null $promotion_from_post
 * @property int|null $reservation_category_id
 * @property-read ReservationCategory|null $reservationCategory
 * @property int|null $seat_reservation_category_id
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_posting_on_promotion_to_post
 * @property Carbon|null $date_of_posting_on_promotion_from_post
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
        'mobile',
        'dob',
        'qualification',
        'promotion_to_post',
        'promotion_from_post',
        'reservation_category_id',
        'seat_reservation_category_id',
        'date_of_joining_service',
        'date_of_posting_on_promotion_to_post',
        'date_of_posting_on_promotion_from_post',
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
            'date_of_posting_on_promotion_to_post' => 'date',
            'date_of_posting_on_promotion_from_post' => 'date',
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

    /**
     * The category of the **seat** this officer occupies — the roster point they
     * were appointed against (business instruction, 2026-09-07).
     *
     * **Not the same question as {@see ReservationCategory()}, and the difference is
     * the whole reason it exists.** An SC officer selected on merit sits on a
     * General point: their own category is SC, the seat's is UR. `CAT` answers the
     * first — it is what puts them on the SC waiting list — and this answers the
     * second, which is what the roster is made of.
     *
     * Null where nothing was recorded, which is *not* the same as sitting on a point
     * of one's own category. A department that keeps no roster leaves it blank.
     *
     * @return BelongsTo<ReservationCategory, $this>
     */
    public function seatReservationCategory(): BelongsTo
    {
        return $this->belongsTo(ReservationCategory::class, 'seat_reservation_category_id');
    }
}
