<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\CalculationStatus;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who may press Calculate, and when (business decision, 2026-11-18).
 *
 *  - Only the DPC Chairman — the person the DPC is allotted to.
 *  - Only once every member of the DPC's committee holding a portal account has
 *    recorded Agree, after the DPC last came back to the committee from the AO.
 *  - Once. Again only when the data changed after it: a correction at the sitting,
 *    the DPC Chairman's own edit at the sitting, or a return to the AO resubmitted
 *    (and agreed to afresh) since.
 *
 * The result, once calculated, is every member's to see (viewCalculation).
 */
final class CalculationGate
{
    public function __construct(
        private readonly SealedCoverService $covers,
        private readonly ProceedingCalculationService $calculations,
    ) {}

    public function mayCalculate(DpcProposal $proposal, ?User $user): bool
    {
        return $this->refusal($proposal, $user) === null;
    }

    /** Why Calculate is not offered to this user now, or null when it is. */
    public function refusal(DpcProposal $proposal, ?User $user): ?string
    {
        if (! $this->covers->isDpcChairman($proposal, $user)) {
            return (string) __('dpc.calculation_gate.only_dpc_chairman');
        }

        // With the committee: approved by the AH and the Secretary, and no return open.
        if ($proposal->status !== DpcProposalStatus::Approved || $proposal->committee_returned_at !== null) {
            return (string) __('dpc.calculation_gate.wrong_stage');
        }

        // A proceeding that has concluded takes no further calculation; its approved
        // result stands (`final_calculation_run_id`).
        $latest = DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->latest('id')->first();
        if ($latest !== null && ! $latest->isOpen()) {
            return (string) __('dpc.calculation_gate.proceeding_closed');
        }

        // The DPC Chairman accepted the DPC before it was held (2026-11-18).
        if (! $this->chairmanAccepted($proposal)) {
            return (string) __('dpc.calculation_gate.awaiting_accept');
        }

        $pending = $this->pendingMembers($proposal);
        if (collect($pending)->contains(fn (array $member): bool => $member['state'] === 'no_account')) {
            return (string) __('dpc.calculation_gate.missing_account');
        }

        if (collect($pending)->contains(fn (array $member): bool => $member['state'] === 'ok_pending')) {
            return (string) __('dpc.calculation_gate.awaiting_ok');
        }

        if ($this->hasCalculated($proposal) && ! $this->needsRecalculation($proposal)) {
            return (string) __('dpc.calculation_gate.already_calculated');
        }

        return null;
    }

    /**
     * Every serving member's latest decision is Agree, recorded after the DPC last
     * came back from the AO — and every member has a login to record it with.
     */
    public function allMembersAgreed(DpcProposal $proposal): bool
    {
        return $this->pendingMembers($proposal) === [] && $this->seats($proposal)->isNotEmpty();
    }

    /**
     * The members whose Agree is still wanted, each with why: no login account to
     * record it with, nothing recorded since the DPC last came back, or a Return.
     *
     * @return list<array{name: string, user_id: int|null, state: 'no_account'|'ok_pending'}>
     */
    public function pendingMembers(DpcProposal $proposal): array
    {
        // Every member OKs every candidate on the record as it now stands; the DPC
        // Chairman alone accepts or rejects (2026-11-18), so no member's Agree is awaited.
        $meetings = app(SittingMeetingService::class);
        $pending = [];
        foreach ($this->seats($proposal) as $seat) {
            $state = match (true) {
                $seat->user_id === null => 'no_account',
                $meetings->candidatesAwaitingOk($proposal, (int) $seat->user_id) !== [] => 'ok_pending',
                default => null,
            };

            if ($state !== null) {
                $pending[] = ['name' => (string) $seat->name, 'user_id' => $seat->user_id, 'state' => $state];
            }
        }

        return $pending;
    }

    /**
     * Whether the DPC Chairman accepted the DPC since it last came to the committee
     * (2026-11-18): their latest decision after the last rejection is Accept.
     */
    public function chairmanAccepted(DpcProposal $proposal): bool
    {
        $lastReturn = (int) DpcCommitteeDecision::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('decision', DpcCommitteeDecision::RETURNED)
            ->max('id');

        $decisions = DpcCommitteeDecision::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('id', '>', $lastReturn)
            ->orderByDesc('id')
            ->get();

        foreach ($decisions as $decision) {
            $user = User::query()->find($decision->user_id);
            if ($user !== null && $this->covers->isDpcChairman($proposal, $user)) {
                return $decision->decision === DpcCommitteeDecision::AGREED;
            }
        }

        return false;
    }

    /**
     * Run a calculation once the gate allows it, under a lock on the proposal: two
     * presses at once re-check the gate in turn, so the second finds it calculated.
     *
     * @template T
     *
     * @param  callable(): T  $calculate
     * @return T|string the result, or why it was refused
     */
    public function calculateUnderLock(DpcProposal $proposal, User $user, callable $calculate): mixed
    {
        return DB::transaction(function () use ($proposal, $user, $calculate): mixed {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            return $this->refusal($locked, $user) ?? $calculate();
        });
    }

    /**
     * The members whose Agree is required: the committee as it now sits, less the
     * CGPSC Chairman's own seat (2026-11-18). The CGPSC Chairman is every DPC's default
     * Chairman and allots it — to a Member, CGPSC or to themselves — and the allottee
     * presides and calculates; that office is not one more Agree to wait for.
     *
     * @return Collection<int, DpcCommitteeMember>
     */
    private function seats(DpcProposal $proposal): Collection
    {
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->where('capacity', '!=', CommitteeCapacity::CgpscChairman->value)
            ->orderBy('sort_order')
            ->get();
    }

    /** Whether the DPC has a result: its proceeding's, or the proposal's before one opens. */
    public function hasCalculated(DpcProposal $proposal): bool
    {
        $proceeding = $this->proceeding($proposal);

        if ($proceeding !== null) {
            return $proceeding->latest_calculation_run_id !== null;
        }

        return $this->latestRun($proposal) !== null;
    }

    /**
     * Whether the data changed after the last result: a correction the result has
     * not seen, or a return to the AO resubmitted since.
     */
    public function needsRecalculation(DpcProposal $proposal): bool
    {
        $proceeding = $this->proceeding($proposal);

        if ($proceeding !== null && $proceeding->latest_calculation_run_id !== null && $this->calculations->isStale($proceeding)) {
            return true;
        }

        $since = $this->lastResubmittedAt($proposal);
        $run = $proceeding?->latest_calculation_run_id !== null
            ? DpcCalculationRun::query()->find($proceeding->latest_calculation_run_id)
            : $this->latestRun($proposal);

        if ($since !== null && $run !== null && $run->executed_at->lte($since)) {
            return true;
        }

        // The DPC Chairman edited a record at the sitting after the result (2026-11-18).
        $edited = app(ChairmanSittingEdit::class)->lastEditAt($proposal);

        return $edited !== null && $run !== null && $run->executed_at->lte($edited);
    }

    /** The DPC's open proceeding, if one has been opened. */
    public function proceeding(DpcProposal $proposal): ?DpcProceeding
    {
        $proceeding = DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->latest('id')->first();

        return $proceeding !== null && $proceeding->isOpen() ? $proceeding : null;
    }

    private function latestRun(DpcProposal $proposal): ?DpcCalculationRun
    {
        return DpcCalculationRun::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', CalculationStatus::Completed->value)
            ->latest('executed_at')
            ->first();
    }

    private function lastResubmittedAt(DpcProposal $proposal): ?Carbon
    {
        $at = DpcCommitteeDecision::query()->where('dpc_proposal_id', $proposal->id)->max('resubmitted_at');

        return $at === null ? null : Carbon::parse((string) $at);
    }
}
