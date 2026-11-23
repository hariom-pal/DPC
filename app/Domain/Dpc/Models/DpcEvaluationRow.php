<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Master\Models\PhType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Master\Services\PromotionCrRules;
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
 * @property bool|null $enquiry_ongoing 2026-11-14: a departmental case or court case ongoing in the DPC's period
 * @property bool|null $punishment_effective 2026-11-14: a punishment in effect in the DPC's period
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
        'ph_type_id',
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
        // 2026-11-14: a case ongoing, a punishment in effect, in the DPC's period — a Yes is Unfit.
        'enquiry_ongoing',
        'punishment_effective',

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
            'enquiry_ongoing' => 'boolean',
            'punishment_effective' => 'boolean',
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

    /** The reason code of "appointed in this year" (Record Reason master). */
    public const APPOINTED_REASON = 'newly_appointed';

    /**
     * Why "appointed in this year" may not be recorded for this year, or null when it
     * may (2026-11-22): checked against the Date of Joining in Service on the Seniority
     * List. Joined on 1 January, the year's record is asked for; joined on 2 January or
     * later of that year, it is "appointed in this year"; any other year, it is not.
     */
    public function appointedYearRefusal(int $year): ?string
    {
        $joined = $this->date_of_joining_service;

        if ($joined === null) {
            return (string) __('dpc.employee_details.missing_record.appointed_no_joining_date');
        }

        if ((int) $joined->year !== $year) {
            return (string) __('dpc.employee_details.missing_record.appointed_other_year', ['year' => $year, 'date' => $joined->format('d-m-Y')]);
        }

        if ($joined->month === 1 && $joined->day === 1) {
            return (string) __('dpc.employee_details.missing_record.appointed_on_first_january', ['year' => $year, 'date' => $joined->format('d-m-Y')]);
        }

        return null;
    }

    /** @var array{0: bool}|null the promotion rule's answer, asked once per row */
    private ?array $appointedRuleAnswer = null;

    /**
     * The latest year recorded "appointed in this year" for a CR or Property File
     * (2026-11-18), or null.
     */
    public function appointedYear(): ?int
    {
        $this->loadMissing('recordGaps.reason');
        $years = $this->recordGaps
            ->filter(fn (DpcEvaluationRecordGap $gap): bool => $gap->reason?->code === self::APPOINTED_REASON)
            ->pluck('year')
            ->map(fn ($year): int => (int) $year);

        return $years->isEmpty() ? null : (int) $years->max();
    }

    /**
     * Whether this DPC's promotion rule says "appointed in this year" ends the years
     * asked for and is not Unfit (2026-11-18), and a year is recorded so.
     */
    public function appointedYearIsFit(): bool
    {
        if ($this->appointedYear() === null || $this->proposal === null) {
            return false;
        }

        $this->appointedRuleAnswer ??= [app(PromotionCrRules::class)->ruleForProposal($this->proposal)?->appointed_year_is_fit === true];

        return $this->appointedRuleAnswer[0];
    }

    /**
     * The earliest year anything is asked for: the year of joining the service, or the
     * year recorded "appointed in this year" when that is later.
     *
     * Whatever the rule says (2026-11-19): no CR or Property File exists before the year
     * an officer was appointed, so none is asked for. The rule decides only whether
     * that year leaves the officer Fit or Unfit (appointedYearIsFit()).
     */
    public function serviceFloorYear(): ?int
    {
        $joined = $this->date_of_joining_service?->year;
        $appointed = $this->appointedYear();

        return $appointed === null ? $joined : max($joined ?? 0, $appointed);
    }

    /** Whether a year is before the one recorded "appointed in this year" (2026-11-19: whatever the rule says). */
    public function isBeforeAppointment(int $year): bool
    {
        $appointed = $this->appointedYear();

        return $appointed !== null && $year < $appointed;
    }

    /**
     * Why the officer is Unfit, from the employee details (business decisions of
     * 2026-11-14): an Unfit Integrity answer in a CR year, a CR year missing for a
     * reason that leaves no record ever (appointed in this year), or a departmental /
     * court case ongoing or a punishment in effect in the DPC's period.
     *
     * Each ground as a translation key and its parameters, so the engine can word it
     * in English and the screens in the reader's language.
     *
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    public function unfitGrounds(): array
    {
        $this->loadMissing(['crGrades.integrityOption', 'recordGaps.reason']);
        $grounds = [];

        foreach ($this->crGrades as $cr) {
            // The Final Departmental View decides where the answer asks for one (2026-11-16).
            if ($cr->integrityFit() === false) {
                $option = (string) $cr->integrityOption?->name_en;
                if ($cr->integrity_departmental_view !== null) {
                    $option .= ' — Final Departmental View: Integrity Not Certified';
                }
                $grounds[] = ['key' => 'dpc.employee_details.unfit.integrity', 'params' => ['year' => $cr->year, 'option' => $option]];
            }
        }

        // The CR of the year just before the DPC year missing is not Unfit: it is held
        // for a Circulation DPC (MoM of 16 November 2026, point 11, superseding the
        // Unfit rule of the same day).

        $appointedIsFit = $this->appointedYearIsFit();

        foreach ($this->recordGaps as $gap) {
            // "Appointed in this year" is not Unfit where the rule says so (2026-11-18).
            if ($appointedIsFit && $gap->reason?->code === self::APPOINTED_REASON) {
                continue;
            }

            if ($gap->reason?->makes_unfit === true) {
                // A Property File reason the Administrator marks Unfit says so (MoM point 12).
                $grounds[] = ['key' => $gap->kind === DpcEvaluationRecordGap::KIND_PROPERTY ? 'dpc.employee_details.unfit.record_reason_property' : 'dpc.employee_details.unfit.record_reason', 'params' => ['year' => $gap->year, 'reason' => $gap->reason->name_en]];
            }
        }

        // A charge-sheet issued with the departmental inquiry / court case ongoing is
        // Rule 2.2's sealed cover, not Unfit (2026-11-18): see isUnderSealedCover().

        if ($this->punishment_effective === true) {
            $grounds[] = ['key' => 'dpc.employee_details.unfit.punishment_effective', 'params' => []];
        }

        return $grounds;
    }

    /**
     * The unfit grounds worded in one language.
     *
     * @return list<string>
     */
    public function unfitReasons(?string $locale = null): array
    {
        return array_map(
            static fn (array $ground): string => (string) __($ground['key'], $ground['params'], $locale),
            $this->unfitGrounds(),
        );
    }

    public function isUnderSealedCover(): bool
    {
        // The first enquiry question (2026-11-18): a charge-sheet issued and the
        // departmental inquiry / court case ongoing keeps the finding in a sealed cover.
        if ($this->enquiry_ongoing === true) {
            return true;
        }

        // A "matter pending" recorded before that question took its place.
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

    /**
     * The officer's kind of disability, from the PH Type master (2026-11-05).
     *
     * @return BelongsTo<PhType, $this>
     */
    public function phType(): BelongsTo
    {
        return $this->belongsTo(PhType::class, 'ph_type_id')->withTrashed();
    }
}
