<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The sitting, from a committee member's side (business instruction, 2026-09-07).
 *
 * > *When all the members click the DPC that is being held, the whole candidate list
 * > should appear. Clicking a candidate shows the full record, and a Call button
 * > appears — the call activates only on the date and time the DPC is scheduled for.
 * > Records should be shown candidate by candidate, and once a candidate's DPC is
 * > done, mark it concluded.*
 *
 * ### Why this is not simply another CGPSC screen
 *
 * Everything under `/cgpsc` is guarded on `role:chairman|member`, and **a committee
 * member holds no role**. Item 7 of the register forbids it — *"do NOT assign any
 * permanent Commission Member role to a user"* — because sitting on one DPC's
 * committee is not an office one holds. So the existing screens are closed to
 * exactly the people this one is for, and the qualification here is a
 * *relationship*: a serving seat on this proposal's committee.
 *
 * ### The Call window
 *
 * A candidate may be called only on the day and at or after the hour the sitting was
 * scheduled for. That is the business rule as stated, and it is enforced here rather
 * than only disabling a button: a screen left open from yesterday would otherwise
 * still post.
 *
 * **Bounded by the day, not by the hour alone.** A sitting scheduled for 14:00 runs
 * into the evening, and refusing a call at 17:00 would stop a committee mid-list. It
 * ends when the day does.
 */
class CommitteeSittingService
{
    /**
     * The DPCs this person sits on, most recently scheduled first.
     *
     * @return Collection<int, DpcProposal>
     */
    public function sittingsFor(User $member): Collection
    {
        $proposalIds = DpcCommitteeMember::query()
            ->serving()
            ->where('user_id', $member->id)
            ->pluck('dpc_proposal_id')
            ->merge(DpcAllotment::query()->current()->where('allotted_to', $member->id)->pluck('dpc_proposal_id'));

        return DpcProposal::query()
            ->whereIn('id', $proposalIds)
            // Not before the CGPSC Chairman has allotted it (2026-11-24): a seat named by the
            // department shows nothing while the DPC is still on its way to the committee.
            ->whereIn('id', DpcAllotment::query()->current()->select('dpc_proposal_id'))
            ->with(['department', 'promotionFromPost', 'promotionToPost'])
            ->orderByDesc('id')
            ->get()
            // A member's seat only once the DPC Chairman opened it to them, or it opened on its own (2026-11-24).
            ->filter(fn (DpcProposal $proposal): bool => $this->isSeatedOn($proposal, $member))
            ->values();
    }

    /**
     * Whether this person holds a serving seat on this DPC's committee.
     *
     * The whole authorisation for these screens. Asked of the committee rather than
     * of a role, for the reason the class docblock gives.
     */
    public function isSeatedOn(DpcProposal $proposal, User $member): bool
    {
        // The DPC Chairman sits on it, and is the person it is allotted to
        // (business instruction, 2026-11-17) — no longer necessarily a seat.
        // A seat counts only once the CGPSC Chairman has allotted the DPC (2026-11-24).
        if (! $this->allotted($proposal)) {
            return false;
        }

        // The DPC Chairman sees it as soon as it is allotted to them.
        if (DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->where('allotted_to', $member->id)->exists()) {
            return true;
        }

        // A member only once the DPC Chairman opened it to the members, or their days
        // passed and it opened on its own (2026-11-24).
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('user_id', $member->id)
            ->exists()
            && app(MemberViewService::class)->isActive($proposal);
    }

    /**
     * Whether the CGPSC Chairman has allotted this DPC (2026-11-24): until then it is not
     * the committee's, and no member sees it, whatever seats the department named.
     */
    public function allotted(DpcProposal $proposal): bool
    {
        return DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->exists();
    }

    /**
     * The sitting this DPC is scheduled for, if one is.
     */
    public function schedule(DpcProposal $proposal): ?DpcSchedule
    {
        return DpcSchedule::query()
            ->whereHas('allotment', fn ($query) => $query->where('dpc_proposal_id', $proposal->id))
            ->current()
            ->latest('id')
            ->first();
    }

    /**
     * Whether the committee may call candidates right now.
     *
     * Three things have to be true, and each of them answers a different question:
     * the sitting has a date at all, that date is today, and the hour it was set for
     * has arrived.
     */
    public function callWindowIsOpen(DpcProposal $proposal, ?Carbon $now = null): bool
    {
        $schedule = $this->schedule($proposal);

        if ($schedule === null) {
            return false;
        }

        $now ??= Carbon::now();

        return $schedule->scheduledFor()->isSameDay($now)
            && $now->greaterThanOrEqualTo($schedule->scheduledFor());
    }

    /**
     * Why the committee may not call yet, in the officer's own words. Null when it
     * may.
     */
    public function callWindowReason(DpcProposal $proposal, ?Carbon $now = null): ?string
    {
        $schedule = $this->schedule($proposal);

        if ($schedule === null) {
            return (string) __('proceeding.sitting.not_scheduled');
        }

        if ($this->callWindowIsOpen($proposal, $now)) {
            return null;
        }

        return (string) __('proceeding.sitting.opens_at', [
            'when' => $schedule->scheduledFor()->format('d-m-Y H:i'),
        ]);
    }

    /**
     * The candidates, in seniority order, each with whatever the committee has
     * recorded against them.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    public function candidates(DpcProposal $proposal): Collection
    {
        return $proposal->evaluationRows()
            ->with(['reservationCategory', 'seatReservationCategory', 'crGrades', 'candidateCall.caller', 'candidateCall.concluder'])
            ->orderBy('sn')
            ->get();
    }

    /**
     * The candidate's Evaluation Chart row, year by year (business instruction,
     * 2026-09-07: *"not only the qualification — the complete Evaluation Chart
     * record is also required"*).
     *
     * **One row per year, carrying all four things the chart records about that
     * year**: the CR grade, whether a loyalty certificate was given, whether an
     * immovable-property return was filed, and whether the officer served in a
     * tribal area. They live in three tables — tribal area rides on the CR row,
     * because the register attaches the benefit to the CR year — and a committee
     * reading down a year needs them side by side, not in three places.
     *
     * A year appears if *any* of the three has something for it. A year with a
     * loyalty certificate and no CR is a real state, and one this screen must show
     * rather than hide: it is the shape of an incomplete record, which is precisely
     * what a committee is there to notice.
     *
     * `null` for a flag means *not recorded*, which is not the same as `false`
     * meaning *recorded as not given*. The screen renders the two differently.
     *
     * @return list<array{year: int, grade: string|null, loyalty: bool|null, property: bool|null, tribal_area: bool|null}>
     */
    public function chartYears(DpcEvaluationRow $row): array
    {
        $row->loadMissing(['crGrades', 'loyaltyFlags', 'propertyFlags']);

        $cr = $row->crGrades->keyBy('year');
        $loyalty = $row->loyaltyFlags->keyBy('year');
        $property = $row->propertyFlags->keyBy('year');

        $years = $cr->keys()
            ->merge($loyalty->keys())
            ->merge($property->keys())
            ->map(static fn ($year): int => (int) $year)
            ->unique()
            ->sort()
            ->values();

        return $years->map(fn (int $year): array => [
            'year' => $year,
            'grade' => $cr->get($year)?->grade,
            'loyalty' => $loyalty->get($year)?->flag,
            'property' => $property->get($year)?->flag,
            'tribal_area' => $cr->get($year)?->tribal_area,
        ])->all();
    }

    /**
     * What the engine made of this candidate, if the DPC has been calculated.
     *
     * The committee deliberates on a result, so the screen shows one where there is
     * one — the score, whether the officer is eligible, which list they fell on and
     * the remark that explains it. Null before the first calculation, which is an
     * ordinary state: a sitting may open before anybody presses Calculate.
     *
     * Read from the **latest** run, because a correction during the sitting produces
     * a new one and the committee must be looking at the current answer.
     */
    public function resultFor(DpcProposal $proposal, DpcEvaluationRow $row): ?DpcCalculationResult
    {
        return DpcCalculationResult::query()
            ->where('dpc_evaluation_row_id', $row->id)
            ->whereHas('run', fn ($query) => $query->where('dpc_proposal_id', $proposal->id))
            ->latest('id')
            ->first();
    }
}
