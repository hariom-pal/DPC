<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vacancy breakdown (PPT S16) — strictly row-based: one row per
 * (vacancy type, reservation category) pair with a count. Never add a
 * column per vacancy type; new vacancy types are new `vacancy_types` master
 * rows, not schema changes.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $vacancy_type_id
 * @property int $reservation_category_id
 * @property int $count
 */
class DpcVacancy extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'vacancy_type_id',
        'reservation_category_id',
        'count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['count' => 'integer'];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<VacancyType, $this>
     */
    public function vacancyType(): BelongsTo
    {
        return $this->belongsTo(VacancyType::class);
    }

    /**
     * @return BelongsTo<ReservationCategory, $this>
     */
    public function reservationCategory(): BelongsTo
    {
        return $this->belongsTo(ReservationCategory::class);
    }
}
