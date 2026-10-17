<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Proceeding\Models\DpcCandidateCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property int|null $tribal_area_service_years
 * @property string|null $note
 * @property bool|null $is_ph
 * @property bool|null $ph_previously_availed
 * @property bool|null $ph_claims_reservation
 * @property string|null $ph_ineligibility_reason
 * @property string|null $ph_verification_status
 * @property string|null $ph_final_decision
 * @property int|null $ph_recorded_by
 * @property Carbon|null $ph_recorded_at
 * @property int|null $cr_channel_undertaking_by
 * @property Carbon|null $cr_channel_undertaking_at
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
        'tribal_area_service_years',
        'note',

        // Rule 2.1 — the PH questionnaire. See PhReservationOutcome.
        'is_ph',
        'ph_previously_availed',
        'ph_claims_reservation',
        'ph_ineligibility_reason',
        'ph_verification_status',
        'ph_final_decision',
        'ph_recorded_by',
        'ph_recorded_at',

        // Page 3 — the undertaking that the officer's CRs are signed as per channel.
        'cr_channel_undertaking_by',
        'cr_channel_undertaking_at',
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
            'date_of_posting_on_promotion_to_post' => 'date',
            'date_of_posting_on_promotion_from_post' => 'date',
            'tribal_area_service_years' => 'integer',
            'is_ph' => 'boolean',
            'ph_previously_availed' => 'boolean',
            'ph_claims_reservation' => 'boolean',
            'ph_recorded_at' => 'datetime',
            'cr_channel_undertaking_at' => 'datetime',
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
     * What the committee recorded against this candidate at the sitting, if
     * anything (business instruction, 2026-09-07).
     *
     * Null until the committee reaches them, which is what lets a screen tell *"not
     * called"* from *"called, nothing recorded"*.
     *
     * A relation rather than a column, and the reason matters: this chart is
     * **projected** from the Seniority List and rebuilt whenever that list is
     * re-uploaded. Anything a sitting had written into a column here would be
     * destroyed by a re-upload.
     *
     * @return HasOne<DpcCandidateCall, $this>
     */
    public function candidateCall(): HasOne
    {
        return $this->hasOne(DpcCandidateCall::class, 'dpc_evaluation_row_id')->latestOfMany();
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
     * The justified, evidenced absences on this row (change register of 2026-08-27,
     * items 14, 15 and 19).
     *
     * @return HasMany<DpcEvaluationRecordGap, $this>
     */
    public function recordGaps(): HasMany
    {
        return $this->hasMany(DpcEvaluationRecordGap::class, 'dpc_evaluation_row_id')
            ->orderBy('kind')
            ->orderBy('year');
    }

    /**
     * @return HasMany<DpcEvaluationPropertyFlag, $this>
     */
    public function propertyFlags(): HasMany
    {
        return $this->hasMany(DpcEvaluationPropertyFlag::class)->orderBy('year');
    }

    /**
     * Every employee-wise document uploaded for this officer, current and superseded.
     *
     * @return HasMany<DpcEvaluationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DpcEvaluationDocument::class, 'dpc_evaluation_row_id')->orderBy('id');
    }

    /**
     * Only the documents in force — a superseded upload is history, not evidence.
     *
     * @return HasMany<DpcEvaluationDocument, $this>
     */
    public function currentDocuments(): HasMany
    {
        return $this->documents()->whereNull('superseded_at');
    }

    public function phOutcome(): PhReservationOutcome
    {
        return PhReservationOutcome::from3($this->is_ph, $this->ph_previously_availed, $this->ph_claims_reservation);
    }
}
