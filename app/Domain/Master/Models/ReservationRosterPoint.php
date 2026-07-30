<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reservation_roster_id
 * @property int $point_no
 * @property int $reservation_category_id
 */
class ReservationRosterPoint extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_roster_id',
        'point_no',
        'reservation_category_id',
    ];

    /**
     * @return BelongsTo<ReservationRoster, $this>
     */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(ReservationRoster::class, 'reservation_roster_id');
    }

    /**
     * @return BelongsTo<ReservationCategory, $this>
     */
    public function reservationCategory(): BelongsTo
    {
        return $this->belongsTo(ReservationCategory::class);
    }
}
