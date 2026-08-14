<?php

declare(strict_types=1);

namespace App\Domain\Review\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supersession takes legal effect when a Review's result is approved —
 * M8 Phase 3, architect ruling AQ-9.
 *
 * **The timing is the whole point.** Phase 1 recorded the *intent* — this DPC
 * corrects that one — at the moment the Review was raised. Nothing was
 * superseded then, and deliberately so: a Review that is cancelled, returned or
 * simply never approved must leave the original standing. Supersession is a
 * consequence of a decision, not of an intention to review.
 *
 * A decorator over M7's `ResultApprovalService` (AD-20), so M7's freeze
 * verification stays empty. A Fresh DPC passes straight through.
 *
 * **The document service is resolved on use, not injected.** Constructor
 * injection closed a cycle in the container: this class needs
 * `ReviewAwareDocumentService`, which needs `DocumentRenderer` — bound to
 * `ReviewAwareDocumentRenderer` — which needs `ResultApprovalService`, bound
 * back to this class. Resolving any one of the three recursed until the process
 * died. Four decorators over one milestone is what makes that reachable: three
 * of them are in each other's dependency graph, and the fourth binding closed
 * the ring. The dependency is only needed *inside* `approve()`, so deferring it
 * breaks the ring without weakening anything.
 */
class ReviewAwareApprovalService extends ResultApprovalService
{
    public function __construct(
        WorkflowEngine $engine,
        ProceedingService $proceedings,
        ProceedingCalculationService $calculations,
        private readonly ReviewDpcService $reviews,
        private readonly ActivityTimelineService $timeline,
        private readonly Container $container,
    ) {
        parent::__construct($engine, $proceedings, $calculations);
    }

    public function approve(
        DpcProceeding $proceeding,
        User $actor,
        Request $request,
        int $promotionAsOnYear,
    ): DpcProceeding {
        $approved = parent::approve($proceeding, $actor, $request, $promotionAsOnYear);

        $link = $this->reviews->linkForReview($approved->proposal);

        if ($link === null || $link->hasTakenEffect()) {
            return $approved;
        }

        DB::transaction(function () use ($link, $approved, $actor): void {
            $link->update(['superseded_at' => now()]);

            $original = $link->original;
            $properties = array_merge($link->fresh()->toTimelineProperties(), [
                'superseded_at' => now()->toIso8601String(),
                'original_dpc_code' => $original?->dpc_code,
                'original_proposal_uuid' => (string) $original?->uuid,
                'review_dpc_code' => $approved->proposal->dpc_code,
                'review_proposal_uuid' => (string) $approved->proposal->uuid,
                'proceeding_uuid' => $approved->uuid,
            ]);

            // Recorded on both threads, as Phase 1 established: a correction
            // chain must be readable from either end.
            $this->timeline->record(
                'dpc_review.supersession_effective',
                $approved->proposal,
                'This Review is now the operative DPC',
                $properties,
                $actor,
            );

            if ($original !== null) {
                $this->timeline->record(
                    'dpc_review.superseded',
                    $original,
                    'Superseded by an approved Review DPC',
                    $properties,
                    $actor,
                );
            }

            // Directives 1-3: the superseded DPC's documents acquire the
            // chain's identifiers and the moment supersession took effect, so
            // "Superseded By" can be printed from the document's own record
            // rather than by re-deriving it at read time.
            $concluded = $original === null ? null : $this->reviews->concludedProceeding($original);

            if ($concluded !== null) {
                $documents = $this->container->make(ReviewAwareDocumentService::class);

                foreach ($documents->allVersions($concluded) as $document) {
                    $documents->recordReference($document, $link->fresh(), superseded: true);
                }
            }

            DpcSupersededByReview::dispatch(
                $link->chain_uuid,
                $link->review_number,
                (string) $original?->uuid,
                $original?->dpc_code,
                (string) $approved->proposal->uuid,
                $approved->proposal->dpc_code,
                $approved->proposal->department_id,
            );
        });

        return $approved->fresh();
    }
}
