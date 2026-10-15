<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Models\DpcCandidateCall;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly ActivityTimelineService $timeline) {}

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
            ->pluck('dpc_proposal_id');

        return DpcProposal::query()
            ->whereIn('id', $proposalIds)
            ->with(['department', 'promotionFromPost', 'promotionToPost'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Whether this person holds a serving seat on this DPC's committee.
     *
     * The whole authorisation for these screens. Asked of the committee rather than
     * of a role, for the reason the class docblock gives.
     */
    public function isSeatedOn(DpcProposal $proposal, User $member): bool
    {
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('user_id', $member->id)
            ->exists();
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

    /**
     * Call a candidate before the committee.
     *
     * @throws WorkflowException when the sitting is not open
     */
    public function call(DpcProposal $proposal, DpcEvaluationRow $row, User $member): DpcCandidateCall
    {
        $proceeding = $this->openProceeding($proposal);

        if (! $this->callWindowIsOpen($proposal)) {
            throw new WorkflowException((string) $this->callWindowReason($proposal));
        }

        return DB::transaction(function () use ($proceeding, $proposal, $row, $member): DpcCandidateCall {
            $call = DpcCandidateCall::query()->firstOrNew([
                'dpc_proceeding_id' => $proceeding->id,
                'dpc_evaluation_row_id' => $row->id,
            ]);

            // A second call of the same candidate re-stamps the row rather than
            // adding another: the question the screen asks is where the committee
            // has got to, not how many times somebody was called back.
            $call->forceFill([
                'called_at' => now(),
                'called_by' => $member->id,
            ])->save();

            $this->timeline->record(
                'dpc_proceeding.candidate_called',
                $proposal,
                sprintf('%s called before the committee by %s', $row->name, $member->name),
                ['sn' => $row->sn, 'officer' => $row->name, 'call_uuid' => $call->uuid],
                $member,
            );

            return $call;
        });
    }

    /**
     * Record that the committee has finished with this candidate.
     *
     * **What "concluded" carries is deliberately thin.** Business has not decided
     * the marking scheme — *"how the marking is to be done we will see later"* — so
     * this records the act and its author, and nothing that would presume the
     * scheme.
     *
     * A candidate must have been called first: concluding somebody the committee
     * never called is a minute that does not describe a sitting.
     */
    public function conclude(DpcProposal $proposal, DpcEvaluationRow $row, User $member, ?string $remarks = null): DpcCandidateCall
    {
        $proceeding = $this->openProceeding($proposal);

        $call = DpcCandidateCall::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('dpc_evaluation_row_id', $row->id)
            ->first();

        if ($call === null || ! $call->isCalled()) {
            throw new WorkflowException((string) __('proceeding.sitting.call_first'));
        }

        return DB::transaction(function () use ($call, $proposal, $row, $member, $remarks): DpcCandidateCall {
            $call->forceFill([
                'concluded_at' => now(),
                'concluded_by' => $member->id,
                'remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : $call->remarks,
            ])->save();

            $this->timeline->record(
                'dpc_proceeding.candidate_concluded',
                $proposal,
                sprintf('The committee concluded %s', $row->name),
                ['sn' => $row->sn, 'officer' => $row->name, 'call_uuid' => $call->uuid],
                $member,
            );

            return $call;
        });
    }

    /**
     * The open sitting for this DPC.
     *
     * Refused rather than created: opening a sitting is the allottee's act and has
     * its own screen, its own guard and its own transition. A committee member
     * arriving before it is opened is early, not authorised to start it.
     *
     * @throws WorkflowException
     */
    private function openProceeding(DpcProposal $proposal): DpcProceeding
    {
        $proceeding = DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->latest('id')
            ->first();

        if ($proceeding === null) {
            throw new WorkflowException((string) __('proceeding.sitting.not_open'));
        }

        return $proceeding;
    }
}
