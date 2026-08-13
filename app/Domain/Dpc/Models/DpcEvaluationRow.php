<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Master\Models\ReservationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One officer's row on the Evaluation / Tabulation Chart (PPT S18) — M4
 * Phase 5. A proposal-time snapshot carrying the standard ten-field
 * employee set; see the migration docblock for why nothing here is a live
 * employee reference.
 *
 * This model holds raw entered data only. `mark` and `remark` are absent by
 * design — they are computed by the M5 Eligibility Engine from these rows
 * (architect directive: no calculations in M4).
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
 * @property int $reservation_category_id
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_posting_on_current_post
 * @property Carbon|null $date_of_posting_on_feeder_post
 * @property int|null $tribal_area_service_years
 * @property string|null $note
 */
class DpcEvaluationRow extends Model
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
        'reservation_category_id',
        'date_of_joining_service',
        'date_of_posting_on_current_post',
        'date_of_posting_on_feeder_post',
        'tribal_area_service_years',
        'note',
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
            'date_of_posting_on_current_post' => 'date',
            'date_of_posting_on_feeder_post' => 'date',
            'tribal_area_service_years' => 'integer',
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
     * @return HasMany<DpcEvaluationCrGrade, $this>
     */
    public function crGrades(): HasMany
    {
        return $this->hasMany(DpcEvaluationCrGrade::class)->orderBy('year');
    }

    /**
     * @return HasMany<DpcEvaluationLoyaltyFlag, $this>
     */
    public function loyaltyFlags(): HasMany
    {
        return $this->hasMany(DpcEvaluationLoyaltyFlag::class)->orderBy('year');
    }

    /**
     * @return HasMany<DpcEvaluationPropertyFlag, $this>
     */
    public function propertyFlags(): HasMany
    {
        return $this->hasMany(DpcEvaluationPropertyFlag::class)->orderBy('year');
    }
}
