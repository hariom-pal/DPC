<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $reservation_category_id
 * @property numeric-string $percentage
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property MasterStatus $status
 */
class ReservationPolicy extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_category_id',
        'percentage',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'percentage' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ReservationCategory, $this>
     */
    public function reservationCategory(): BelongsTo
    {
        return $this->belongsTo(ReservationCategory::class);
    }
}
