<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * APPROVE BY CGPSC and CANCEL DPC (PPT S33–S34) — M7 Phase 3.
 *
 * Both transitions **close** the sitting, and both record who closed it and
 * when (architect Directive 1). Once closed the proceeding is frozen at the
 * model layer: a correction after conclusion is a Review DPC (M8), never an
 * edit.
 *
 * **A stale calculation can never be approved** (architect ruling AQ-2). The
 * check is by correction sequence rather than timestamp, and it names the
 * officers affected rather than reporting only that something is out of date —
 * a message the Commission cannot act on is a message it will click past.
 */
class ResultApprovalService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly ProceedingService $proceedings,
        private readonly ProceedingCalculationService $calculations,
    ) {}

    /**
     * S34 — APPROVE BY CGPSC.
     *
     * Signing is **Phase 4**, with document generation: there is nothing to
     * sign until the result document exists. `signature_id` stays null until
     * then, and the workflow definition says `signature => false` rather than
     * claiming a requirement nothing yet honours.
     */
    public function approve(
        DpcProceeding $proceeding,
        User $actor,
        Request $request,
        int $promotionAsOnYear,
    ): DpcProceeding {
        return DB::transaction(function () use ($proceeding, $actor, $request, $promotionAsOnYear): DpcProceeding {
            // Re-read under a lock before deciding anything. Two simultaneous
            // approvals would otherwise both pass the status check and both
            // proceed — the M4 pattern, not M3's (TD-5).
            $locked = DpcProceeding::query()->lockForUpdate()->findOrFail($proceeding->id);

            $this->assertOpen($locked);
            $this->assertConductor($locked, $actor);

            $run = $this->calculations->latestRun($locked);

            if ($run === null) {
                throw new WorkflowException(
                    'This DPC has not been calculated yet, so there is no result to approve.'
                );
            }

            // AQ-2: never approve a stale calculation.
            if ($this->calculations->isStale($locked)) {
                $affected = $this->calculations->correctionsSinceCalculation($locked)
                    ->pluck('officer_name')->unique()->implode(', ');

                throw new WorkflowException(
                    'Corrections have been recorded since this result was calculated'
                    .($affected === '' ? '' : ' (for '.$affected.')')
                    .'. Calculate again and review the result before approving it.'
                );
            }

            $from = $this->proceedings->state($locked->proposal);
            $def = $this->engine->assertAllowed('dpc_proceeding', 'approve_result', $from, $actor);

            $locked->update([
                'status' => DpcProceedingStatus::ResultApproved->value,
                'promotion_as_on_year' => $promotionAsOnYear,
                // The run the Commission attested to. It is pinned here and
                // never moves — a later calculation cannot retrospectively
                // change what was approved.
                'final_calculation_run_id' => $run->id,
                'approved_at' => now(),
                'closed_by' => $actor->id,
                'closed_by_name' => $actor->name,
                'closed_at' => now(),
            ]);

            $this->engine->record(
                $locked->proposal, 'dpc_proceeding', 'approve_result', $from, $def, $actor, $request,
                null, null, $locked->proposal->department_id,
                [
                    'proceeding_uuid' => $locked->uuid,
                    'run_uuid' => $run->uuid,
                    'rule_version' => $run->rule_version,
                    'promotion_as_on_year' => $promotionAsOnYear,
                    'main_list_count' => $run->main_list_count,
                    'waiting_list_count' => $run->waiting_list_count,
                    'calculated_through_sequence' => $locked->calculated_through_sequence,
                    // Directive 5 — an unresolved business rule is carried on
                    // the record, never resolved in code. A result approved
                    // while flagged is provisional, and the record says so.
                    'has_pending_decisions' => $run->has_pending_decisions,
                    'closed_by_id' => $actor->id,
                    'closed_by_name' => $actor->name,
                    'closed_at' => now()->toIso8601String(),
                ],
                [$actor->id],
            );

            return $locked->refresh();
        });
    }

    /**
     * S33 — CANCEL DPC. Terminal, and a reason is required.
     *
     * There is no un-cancel: re-running a cancelled DPC is a fresh proposal,
     * and correcting one is a Review DPC. A reversible cancellation would make
     * the record of what the Commission decided depend on what it later
     * changed its mind about.
     */
    public function cancel(
        DpcProceeding $proceeding,
        User $actor,
        Request $request,
        string $reason,
    ): DpcProceeding {
        return DB::transaction(function () use ($proceeding, $actor, $request, $reason): DpcProceeding {
            $locked = DpcProceeding::query()->lockForUpdate()->findOrFail($proceeding->id);

            $this->assertOpen($locked);
            $this->assertConductor($locked, $actor);

            $from = $this->proceedings->state($locked->proposal);
            $def = $this->engine->assertAllowed('dpc_proceeding', 'cancel_dpc', $from, $actor);

            $locked->update([
                'status' => DpcProceedingStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'closed_by' => $actor->id,
                'closed_by_name' => $actor->name,
                'closed_at' => now(),
            ]);

            $this->engine->record(
                $locked->proposal, 'dpc_proceeding', 'cancel_dpc', $from, $def, $actor, $request,
                $reason, null, $locked->proposal->department_id,
                [
                    'proceeding_uuid' => $locked->uuid,
                    'cancellation_reason' => $reason,
                    'closed_by_id' => $actor->id,
                    'closed_by_name' => $actor->name,
                    'closed_at' => now()->toIso8601String(),
                ],
                [$actor->id],
            );

            return $locked->refresh();
        });
    }

    /**
     * S34 — the Main List and Waiting List of an approved (or calculated) run,
     * in list position order.
     *
     * @return array{main: EloquentCollection<int, DpcCalculationResult>, waiting: EloquentCollection<int, DpcCalculationResult>, others: EloquentCollection<int, DpcCalculationResult>}
     */
    public function lists(DpcCalculationRun $run): array
    {
        $results = DpcCalculationResult::query()
            ->where('dpc_calculation_run_id', $run->id)
            ->orderBy('list_position')
            ->orderBy('sn')
            ->get();

        // `list_type` is cast to a ListType enum, so compare enums — a string
        // comparison here would silently return three empty lists.
        return [
            'main' => $results->where('list_type', ListType::Main)->values(),
            'waiting' => $results->where('list_type', ListType::Waiting)->values(),
            'others' => $results->where('list_type', ListType::None)->values(),
        ];
    }

    private function assertOpen(DpcProceeding $proceeding): void
    {
        if (! $proceeding->isOpen()) {
            throw new WorkflowException('This proceeding has already been concluded.');
        }
    }

    /**
     * The member conducting the sitting concludes it. Checked here as well as
     * in the policy so a non-HTTP caller is bound too (AQ-6).
     */
    private function assertConductor(DpcProceeding $proceeding, User $actor): void
    {
        if ($proceeding->conducted_by !== $actor->id) {
            throw new WorkflowException('This proceeding is being conducted by another member.');
        }
    }
}
