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
 * 2026-08-13). `date_of_posting_on_promotion_to_post` is this table's "Date of Posting
 * on Promotion To Post" — kept under its Phase 3 name because renaming an
 * approved column is a breaking change.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $sn
 * @property string $status
 * @property string|null $source
 * @property string|null $employee_number
 * @property string|null $name
 * @property string|null $mobile
 * @property Carbon|null $dob
 * @property string|null $qualification
 * @property string|null $promotion_to_post
 * @property string|null $promotion_from_post
 * @property Carbon|null $date_of_joining_service
 * @property Carbon|null $date_of_posting_on_promotion_to_post
 * @property Carbon|null $date_of_posting_on_promotion_from_post
 * @property int $reservation_category_id
 * @property int|null $seat_reservation_category_id
 * @property int|null $roster_position
 * @property Carbon|null $date_of_retirement
 * @property Carbon|null $deputation_from
 * @property Carbon|null $deputation_to
 */
class DpcWorkingOfficer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'sn',

        /*
         | Whether this row is an officer or an empty chair (business directive,
         | 2026-09-02). A `vacant` row is how the chart states a clear vacancy
         | explicitly, instead of leaving it to be inferred from a subtraction and
         | then re-typed in a breakdown grid.
         */
        'status',

        /*
         | How this officer came to hold the post (business directive,
         | 2026-09-02): `promotion`, `deputation` or `direct`. It says which of
         | the Recruitment Rule's two quotas his chair is consuming, which is
         | what lets the promotion vacancy be counted rather than derived from a
         | percentage of a total.
         */
        'source',

        'employee_number',
        'name',
        'mobile',
        'dob',
        'qualification',
        'promotion_to_post',
        'promotion_from_post',
        'date_of_joining_service',
        'date_of_posting_on_promotion_to_post',
        'date_of_posting_on_promotion_from_post',
        'reservation_category_id',
        'seat_reservation_category_id',
        'roster_position',

        // Change register of 2026-08-27, items 3 and 4. `deputation_date` became
        // `deputation_from` because one date can only say when a deputation began,
        // and the register's test is whether it *overlaps* the vacancy period.
        'date_of_retirement',
        'deputation_from',
        'deputation_to',
    ];

    /**
     * The two states a chart row may be in.
     */
    public const STATUS_WORKING = 'working';

    public const STATUS_VACANT = 'vacant';

    /**
     * The three ways an officer can have come into the post.
     *
     * `deputation` is not a recruitment quota — it is the absence of one. An
     * officer on deputation into the post holds it temporarily and consumes
     * neither the promotion nor the direct quota, so his chair stays countable
     * as a vacancy.
     */
    public const SOURCE_PROMOTION = 'promotion';

    public const SOURCE_DEPUTATION = 'deputation';

    public const SOURCE_DIRECT = 'direct';

    /**
     * @return list<string>
     */
    public static function sources(): array
    {
        return [self::SOURCE_PROMOTION, self::SOURCE_DEPUTATION, self::SOURCE_DIRECT];
    }

    /**
     * An empty chair rather than an officer.
     *
     * Every clear vacancy on a proposal is one of these, so this is the predicate
     * the vacancy engine counts rather than a subtraction it performs.
     */
    public function isVacant(): bool
    {
        return $this->status === self::STATUS_VACANT;
    }

    /**
     * An officer actually in post.
     *
     * Retirement and deputation are only ever asked of these: an empty chair
     * cannot retire, and cannot be sent on deputation.
     */
    public function isWorking(): bool
    {
        return ! $this->isVacant();
    }

    /**
     * What to show where a name would go.
     *
     * A vacant row has no name by design, and every screen and PDF that lists the
     * chart needs *something* legible in that column. Kept on the model so the one
     * wording is shared rather than re-invented per view.
     */
    public function displayName(): string
    {
        return $this->isVacant()
            ? __('dpc.working_officers.vacant_post')
            : (string) $this->name;
    }

    /**
     * Whether this row consumes a quota chair for the whole DPC Year.
     *
     * **The single predicate the vacancy arithmetic rests on.** A chair counts as
     * filled only if somebody substantively holds it *through* the year, so four
     * things disqualify a row, and each of them is a vacancy the DPC or Direct
     * Recruitment will have to fill:
     *
     *  - it is a vacant post, so nobody holds it;
     *  - the officer came on deputation, so he holds it temporarily;
     *  - he retires inside the vacancy window, so it falls empty mid-year;
     *  - he is himself away on deputation across the window, so it is empty now.
     *
     * The window is passed in rather than read here, because it belongs to the
     * proposal's DPC Year and this model must not consult the clock.
     */
    public function substantivelyOccupies(Carbon $from, Carbon $to): bool
    {
        if ($this->isVacant() || $this->source === self::SOURCE_DEPUTATION) {
            return false;
        }

        if ($this->date_of_retirement !== null && $this->date_of_retirement->betweenIncluded($from, $to)) {
            return false;
        }

        return ! $this->isOnDeputationDuring($from, $to);
    }

    /**
     * Why this row's chair is free, in the wording of the `vacancy_types` master.
     *
     * Null when the chair is not free at all. The grounds are the register's own:
     * an empty chair is `clear`, and an officer leaving frees his chair *on the
     * ground he arrived by* — *"promotion se aaya tha to promotion se 1 vacancy
     * badh gayee"*.
     */
    public function vacancyGround(Carbon $from, Carbon $to): ?string
    {
        if ($this->isVacant()) {
            return 'clear';
        }

        if ($this->substantivelyOccupies($from, $to)) {
            return null;
        }

        return match ($this->source) {
            self::SOURCE_DEPUTATION => 'from_deputation',
            self::SOURCE_PROMOTION => 'from_promotion',

            // A direct recruit's chair falls to Direct Recruitment. It is a real
            // vacancy and it is reported, but the master holds no type for it and
            // this DPC does not fill it.
            default => null,
        };
    }

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
            'roster_position' => 'integer',
            'date_of_retirement' => 'date',
            'deputation_from' => 'date',
            'deputation_to' => 'date',
        ];
    }

    /**
     * Whether this officer retires inside the vacancy period (item 3).
     *
     * Inclusive of both ends: the register names 1 January and 30 November as the
     * window, and an officer retiring *on* 30 November has vacated the post within
     * it.
     */
    public function retiresDuring(Carbon $from, Carbon $to): bool
    {
        return $this->date_of_retirement !== null
            && $this->date_of_retirement->betweenIncluded($from, $to);
    }

    /**
     * Whether this officer's deputation overlaps the vacancy period (item 4).
     *
     * **Overlap, not containment.** A deputation from the previous November to the
     * following February covers the whole window without starting or ending inside
     * it, and the post is vacant throughout — so the test is that the deputation
     * begins on or before the window ends and has not already ended before it
     * began.
     *
     * **A null `deputation_to` means still on deputation.** That is the clearest
     * case the rule is about, so it counts; the alternative reading — "no period
     * recorded, therefore no overlap" — would exclude it.
     *
     * An officer with no `deputation_from` is not on deputation at all.
     */
    public function isOnDeputationDuring(Carbon $from, Carbon $to): bool
    {
        if ($this->deputation_from === null) {
            return false;
        }

        if ($this->deputation_from->gt($to)) {
            return false;
        }

        return $this->deputation_to === null || $this->deputation_to->gte($from);
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
