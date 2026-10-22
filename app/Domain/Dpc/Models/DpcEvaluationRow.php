<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\SealedCoverReason;
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
 * @property bool|null $vigilance_pending rule 2.2: null not answered, false nothing pending, true a matter is pending
 * @property int|null $sealed_cover_reason_id
 * @property Carbon|null $vigilance_date
 * @property string|null $vigilance_remarks
 * @property Carbon|null $vigilance_recorded_at
 * @property-read SealedCoverReason|null $sealedCoverReason
 * @property string|null $dob_order_number
 * @property Carbon|null $dob_order_date
 * @property string|null $joining_order_number
 * @property Carbon|null $joining_order_date
 * @property Carbon|null $service_record_undertaking_at
 * @property bool|null $unsanctioned_deputation rule 2.3
 * @property bool $refused_promotion rule 4.4
 * @property int|null $refused_promotion_year
 * @property Carbon|null $declarations_recorded_at
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
        // Rule 2.1: the Administrative Head's verification of a claim.
        'ph_verified_by',
        'ph_verified_at',
        'ph_verification_remarks',

        // Page 3 — the undertaking that the officer's CRs are signed as per channel.
        'cr_channel_undertaking_by',
        'cr_channel_undertaking_at',

        // Rule 2.2 — departmental enquiry and the sealed cover.
        'vigilance_pending',
        'sealed_cover_reason_id',
        'vigilance_date',
        'vigilance_remarks',
        'vigilance_recorded_by',
        'vigilance_recorded_at',

        // Rule 4.4 — the orders behind the date of birth and of joining.
        'dob_order_number',
        'dob_order_date',
        'joining_order_number',
        'joining_order_date',
        'service_record_undertaking_by',
        'service_record_undertaking_at',

        // Rule 2.3 and rule 4.4 — deputation without a sanctioned setup; promotion refused.
        'unsanctioned_deputation',
        'refused_promotion',
        'refused_promotion_year',
        'declarations_recorded_by',
        'declarations_recorded_at',
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
            'ph_verified_at' => 'datetime',
            'cr_channel_undertaking_at' => 'datetime',
            'vigilance_pending' => 'boolean',
            'vigilance_date' => 'date',
            'vigilance_recorded_at' => 'datetime',
            'dob_order_date' => 'date',
            'joining_order_date' => 'date',
            'service_record_undertaking_at' => 'datetime',
            'unsanctioned_deputation' => 'boolean',
            'refused_promotion' => 'boolean',
            'refused_promotion_year' => 'integer',
            'declarations_recorded_at' => 'datetime',
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
     * The pending departmental enquiry, charge-sheet, show-cause or suspension the
     * Authorized Officer recorded (rule 2.2).
     *
     * @return BelongsTo<SealedCoverReason, $this>
     */
    public function sealedCoverReason(): BelongsTo
    {
        return $this->belongsTo(SealedCoverReason::class)->withTrashed();
    }

    /**
     * Whether the DPC's finding on this officer is kept in a sealed cover (rule 2.2).
     */
    /**
     * Rule 4.4: the officer refused promotion to this post in the year before the DPC
     * year, which makes the officer unfit for it.
     */
    public function refusedPromotionLastYear(): bool
    {
        $year = $this->proposal?->dpc_year ?: $this->proposal?->proposal_date?->year;

        return $this->refused_promotion === true && $year !== null && $this->refused_promotion_year === (int) $year - 1;
    }

    /** Rule 2.1: a PH claim the Administrative Head has verified — the officer competes for a PH seat. */
    public function holdsVerifiedPhClaim(): bool
    {
        return $this->phOutcome()->isClaim() && $this->ph_verification_status === 'verified';
    }

    public function isUnderSealedCover(): bool
    {
        return $this->vigilance_pending === true && $this->sealedCoverReason?->sealsTheCover() === true;
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
