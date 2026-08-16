<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Circulation\ValueObjects\PendingBusinessDecision;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Enums\HoldReason;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Circulation DPC — raising a note against a concluded DPC (PPT S45, S47;
 * C3/C7) — M9 Phase 1.
 *
 * **This service performs no DPC execution (AD-23).** It does not invoke
 * `DpcCalculationService`, `EligibilityService`, `ZoneOfConsiderationService`,
 * `ScoringService`, `SuperSeatService`, `ProceedingService` or any calculation
 * decorator, and it never recalculates, re-scores, determines eligibility or a
 * zone, or conducts a proceeding. The origin's approved result is read as
 * data — **reading a stored decision is not making one.**
 *
 * **It is its own aggregate (AD-24).** No `dpc_proposals` row is created,
 * nothing extends `DpcProposal`, and the origin is referenced by foreign key
 * and never written.
 *
 * **It releases no hold.** M7 declares `dpc_proceeding_holds.released_at` as
 * M9's seam, and Phase 2 writes it at signature (AQ-17). Phase 1 records the
 * department's evidence and stops.
 */
class CirculationService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * DPCs this department may raise a circulation against.
     *
     * Three conditions, and each refuses for its own reason: the sitting has
     * **concluded** (a promotion cannot be reserved by a DPC still running),
     * at least one hold is **still in force** (there is nothing to resolve
     * otherwise), and the DPC has **not been superseded** by a Review (AQ-18 —
     * confirming a promotion under a list that no longer governs is exactly
     * what M8's watermark exists to prevent).
     *
     * @return EloquentCollection<int, DpcProposal>
     */
    public function eligibleOrigins(int $departmentId): EloquentCollection
    {
        $withHolds = DpcProceedingHold::query()
            ->whereNull('released_at')
            ->select('dpc_proceeding_id')
            ->distinct()
            ->pluck('dpc_proceeding_id');

        $proposalIds = DpcProceeding::query()
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->whereIn('id', $withHolds)
            ->pluck('dpc_proposal_id')
            ->unique()
            ->values();

        return DpcProposal::query()
            ->where('department_id', $departmentId)
            ->whereIn('id', $proposalIds)
            ->whereNotIn('id', $this->supersededProposalIds())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The holds still in force on a DPC — what a circulation may cover.
     *
     * @return EloquentCollection<int, DpcProceedingHold>
     */
    public function outstandingHolds(DpcProposal $origin): EloquentCollection
    {
        $proceeding = $this->concludedProceeding($origin);

        if ($proceeding === null) {
            /** @var EloquentCollection<int, DpcProceedingHold> $empty */
            $empty = DpcProceedingHold::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNull('released_at')
            ->orderBy('sn')
            ->get();
    }

    /**
     * **BD-15 detection.** Does this set of holds include a CR ground?
     *
     * Returns the structured decision when it does, `null` when it does not.
     * **Nothing is scored to reach this answer** — the ground is read from
     * `reason_code`, which is why the check is admissibility rather than
     * outcome, and why it can live at `raise()` rather than needing a
     * calculation AD-23 forbids.
     *
     * Why this is blocked at all: resolving a CR-ground hold always re-decides
     * the officer's mark. The frozen engine scores *"the five most recent
     * available CR years"*, so a held officer's window is incomplete by
     * definition; supplying the missing grade grows the window, can make a
     * Super Seat reachable for the first time — which grants notional
     * seniority and **reorders the main list for officers who were never
     * notified** — and can trip either hard gate. Whether S45's CR ground may
     * be routed to a Review instead is a question for CGPSC.
     *
     * @param  EloquentCollection<int, DpcProceedingHold>  $holds
     */
    public function assessGrounds(EloquentCollection $holds): ?PendingBusinessDecision
    {
        $crHolds = $holds->filter(
            static fn (DpcProceedingHold $hold): bool => $hold->reason_code === HoldReason::CrYearsMissing
        );

        if ($crHolds->isEmpty()) {
            return null;
        }

        return new PendingBusinessDecision(
            code: 'BD-15',
            title: 'A CR-ground hold cannot yet be resolved by circulation',
            question: 'S45 names "CR not reported for two or more years" as a circulation ground. Resolving it '
                .'always re-decides the officer\'s marks, and can reorder the promotion list for officers who '
                .'were never notified. May this ground be routed to a Review DPC instead? Awaiting CGPSC.',
            affected: $crHolds->map(static fn (DpcProceedingHold $hold): array => [
                'hold_uuid' => $hold->uuid,
                'sn' => $hold->sn,
                'officer_name' => $hold->officer_name,
                'reason_code' => $hold->reason_code->value,
                'reason' => $hold->reason_code->label(),
                'detail' => $hold->detail,
            ])->values()->all(),
            notDone: [
                'No calculation, scoring, eligibility or zone determination was performed.',
                'The officer was not escalated to a Review DPC automatically.',
                'No hold was released and no promotion was decided.',
                'The origin DPC and its approved result are unchanged.',
            ],
            route: 'docs/PENDING_BUSINESS_DECISIONS.md',
        );
    }

    /**
     * S47 — raise a circulation note against a concluded DPC.
     *
     * @param  array<int, array{hold_uuid:string, resolution_detail:string, resolution_reference?:string|null, resolution_received_on:string}>  $items
     *
     * @throws PendingBusinessDecisionException when any selected hold is a CR ground (BD-15)
     * @throws WorkflowException when the origin or the selection is not admissible
     */
    public function raise(
        DpcProposal $origin,
        User $actor,
        Carbon $proposalDate,
        array $items,
    ): DpcCirculation {
        $proceeding = $this->assertCirculable($origin, $actor);

        if ($items === []) {
            throw new WorkflowException('Select at least one held officer for the circulation.');
        }

        $holds = $this->resolveHolds($proceeding, $items);

        // BD-15 — detect, refuse, record. Deliberately *before* the
        // transaction: nothing is written except the audit event, and the
        // event is written whether or not a circulation follows, because the
        // fact worth keeping is that a department asked and the system could
        // not answer.
        $pending = $this->assessGrounds($holds);

        if ($pending !== null) {
            $this->recordPendingDecision($origin, $actor, $pending);

            throw new PendingBusinessDecisionException($pending);
        }

        return DB::transaction(function () use ($origin, $proceeding, $actor, $proposalDate, $items, $holds): DpcCirculation {
            $circulation = DpcCirculation::query()->create([
                'department_id' => $origin->department_id,
                'origin_dpc_proposal_id' => $origin->id,
                'origin_dpc_proceeding_id' => $proceeding->id,
                'origin_dpc_code' => $origin->dpc_code,
                'origin_dpc_date' => $origin->proposal_date,
                'proposal_date' => $proposalDate,
                'status' => CirculationStatus::Draft->value,
                'raised_by' => $actor->id,
                'raised_by_name' => $actor->name,
                'raised_at' => now(),
            ]);

            foreach ($items as $item) {
                $hold = $holds->firstWhere('uuid', $item['hold_uuid']);

                if ($hold === null) {
                    continue;
                }

                DpcCirculationItem::query()->create([
                    'dpc_circulation_id' => $circulation->id,
                    'dpc_proceeding_hold_id' => $hold->id,
                    'sn' => $hold->sn,
                    'officer_name' => $hold->officer_name,
                    'reason_code' => $hold->reason_code->value,
                    'resolution_detail' => $item['resolution_detail'],
                    'resolution_reference' => $item['resolution_reference'] ?? null,
                    'resolution_received_on' => Carbon::parse($item['resolution_received_on']),
                ]);
            }

            $circulation->load('items');

            // Recorded on the **origin proposal** as subject, as M6, M7 and M8
            // all do, so "show me everything that happened to this DPC" stays
            // one ordered query. A circulation is part of that DPC's history,
            // not a separate thread.
            $this->timeline->record(
                'dpc_circulation.raised',
                $origin,
                'Circulation raised for '.$circulation->items->count().' held officer(s)',
                array_merge(
                    $circulation->toTimelineProperties(),
                    ['items' => $circulation->items
                        ->map(static fn (DpcCirculationItem $item): array => $item->toTimelineProperties())
                        ->all()],
                ),
                $actor,
            );

            return $circulation;
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * The concluded sitting for a DPC, or null if it has none.
     *
     * **Read through the model, never through `ProceedingService`** (AD-23):
     * that service *conducts* a sitting, and a circulation never opens,
     * corrects, calculates, approves or cancels one.
     */
    public function concludedProceeding(DpcProposal $origin): ?DpcProceeding
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $origin->id)
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->latest('id')
            ->first();
    }

    /**
     * Proposals a Review has superseded (AQ-18). Read directly from M8's link
     * table — a query, not a dependency on M8's service.
     *
     * @return list<int>
     */
    private function supersededProposalIds(): array
    {
        return DpcReviewLink::query()
            ->whereNotNull('superseded_at')
            ->pluck('original_dpc_proposal_id')
            ->unique()
            ->values()
            ->all();
    }

    private function assertCirculable(DpcProposal $origin, User $actor): DpcProceeding
    {
        if ($origin->department_id !== $actor->department_id) {
            throw new WorkflowException('That DPC belongs to another department.');
        }

        $proceeding = $this->concludedProceeding($origin);

        if ($proceeding === null) {
            throw new WorkflowException(
                'A circulation may only be raised against a DPC whose proceeding has concluded.'
            );
        }

        if (in_array($origin->id, $this->supersededProposalIds(), true)) {
            throw new WorkflowException(
                'This DPC has been superseded by a Review and no longer governs. Raise the circulation '
                .'against the operative DPC in the correction chain.'
            );
        }

        return $proceeding;
    }

    /**
     * Load the selected holds, refusing anything that is not this DPC's, is
     * already released, or is already covered by an open circulation.
     *
     * @param  array<int, array{hold_uuid:string, resolution_detail:string, resolution_reference?:string|null, resolution_received_on:string}>  $items
     * @return EloquentCollection<int, DpcProceedingHold>
     */
    private function resolveHolds(DpcProceeding $proceeding, array $items): EloquentCollection
    {
        $uuids = array_values(array_unique(array_map(
            static fn (array $item): string => $item['hold_uuid'],
            $items,
        )));

        $holds = DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNull('released_at')
            ->whereIn('uuid', $uuids)
            ->get();

        if ($holds->count() !== count($uuids)) {
            throw new WorkflowException(
                'One or more selected officers are not held on this DPC, or their hold has already been '
                .'released.'
            );
        }

        $alreadyCovered = DpcCirculationItem::query()
            ->whereIn('dpc_proceeding_hold_id', $holds->pluck('id'))
            ->whereHas('circulation', static function ($query): void {
                $query->whereNotIn('status', [
                    CirculationStatus::Cancelled->value,
                ]);
            })
            ->exists();

        if ($alreadyCovered) {
            throw new WorkflowException(
                'One or more of these officers is already covered by an open circulation.'
            );
        }

        return $holds;
    }

    /**
     * The audit event for a refusal.
     *
     * **Written even though nothing else is**, because the fact worth keeping
     * is that a department tried to resolve a held promotion and the system
     * declined to decide. Without it, a case blocked on CGPSC is
     * indistinguishable from one nobody ever raised — and BD-13 (how long a
     * promotion may stay held) cannot be answered from an empty record.
     */
    private function recordPendingDecision(
        DpcProposal $origin,
        User $actor,
        PendingBusinessDecision $pending,
    ): void {
        $this->timeline->record(
            'dpc_circulation.pending_business_decision',
            $origin,
            $pending->summary(),
            $pending->toArray(),
            $actor,
        );
    }
}
