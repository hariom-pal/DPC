<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The DPC sitting (PPT S31–S34) — M7 Phase 1.
 *
 * Continues the CGPSC-side story begun by `AllotmentService` and
 * `SchedulingService`, on the **same subject** (the proposal) so that "show me
 * everything that happened to this DPC" remains one ordered query over the
 * timeline. Splitting the subject would split the legal record.
 *
 * Phase 1 opens a proceeding and reads its history. Corrections are Phase 2;
 * holds, result and approval are Phase 3; documents are Phase 4.
 */
class ProceedingService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly AllotmentService $allotments,
        private readonly SchedulingService $scheduling,
    ) {}

    /**
     * S31/S32 — the allottee opens the sitting.
     *
     * The actual opening time is always recorded, whether or not it matches
     * the scheduled slot (architect ruling, AQ-5). Whether opening *early* is
     * permitted at all is configuration, default permissive, so a government
     * notification can tighten it without a code change.
     */
    public function open(DpcProposal $proposal, User $member, Request $request): DpcProceeding
    {
        $allotment = $this->assertAllottee($proposal, $member);
        $schedule = $this->scheduling->current($proposal);

        if ($schedule === null) {
            throw new WorkflowException('This DPC has not been scheduled yet, so the proceeding cannot be opened.');
        }

        $existing = $this->current($proposal);

        if ($existing !== null) {
            throw new WorkflowException('A proceeding for this DPC is already open.');
        }

        if ($this->concluded($proposal) !== null) {
            throw new WorkflowException(
                'This DPC has already been conducted. A concluded DPC is corrected through a Review DPC, '
                .'never by re-opening it.'
            );
        }

        $this->assertScheduledTimeReached($schedule);
        $this->ensureProposalUuid($proposal);

        $from = $this->allotments->state($proposal);
        $def = $this->engine->assertAllowed('dpc_proceeding', 'open', $from, $member);

        return DB::transaction(function () use ($proposal, $schedule, $member, $request, $def, $from, $allotment): DpcProceeding {
            $proceeding = DpcProceeding::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'dpc_schedule_id' => $schedule->id,
                'status' => DpcProceedingStatus::InProceeding->value,
                'conducted_by' => $member->id,
                // Snapshot, not a reference: a renamed or removed user must not
                // change who conducted this sitting.
                'conducted_by_name' => $member->name,
                'proposal_version' => $this->chartFingerprint($proposal),
                'opened_at' => now(),
            ]);

            $this->engine->record(
                $proposal, 'dpc_proceeding', 'open', $from, $def, $member, $request,
                null, null, $proposal->department_id,
                // Enough for an export, a certificate, or a replaying
                // subscriber to act without reading a business table.
                [
                    'proceeding_uuid' => $proceeding->uuid,
                    'allotment_uuid' => $allotment->uuid,
                    'schedule_uuid' => $schedule->uuid,
                    'dpc_date' => $schedule->dpc_date->toDateString(),
                    'dpc_time' => $schedule->dpc_time,
                    'scheduled_for' => $schedule->scheduledFor()->toIso8601String(),
                    'opened_at' => $proceeding->opened_at->toIso8601String(),
                    'timezone' => (string) config('dpc.timezone'),
                    'conducted_by_id' => $member->id,
                    'conducted_by_name' => $member->name,
                    'proposal_version' => $proceeding->proposal_version,
                ],
                [$member->id],
            );

            return $proceeding;
        });
    }

    /**
     * The open proceeding for a proposal, or null.
     */
    public function current(DpcProposal $proposal): ?DpcProceeding
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->open()
            ->latest('opened_at')
            ->first();
    }

    /**
     * The concluded proceeding for a proposal, or null. A DPC is conducted
     * once; this is what makes re-opening impossible.
     */
    public function concluded(DpcProposal $proposal): ?DpcProceeding
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', '!=', DpcProceedingStatus::InProceeding->value)
            ->latest('opened_at')
            ->first();
    }

    /**
     * The proceeding workflow state for a proposal.
     *
     * Derived from the proceeding record, never stored on the frozen proposal
     * — the rule M6 established and this milestone inherits. Before a
     * proceeding exists the state is the CGPSC workflow's own.
     */
    public function state(DpcProposal $proposal): string
    {
        $proceeding = DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->latest('opened_at')
            ->first();

        return $proceeding === null
            ? $this->allotments->state($proposal)
            : $proceeding->status->workflowState();
    }

    /**
     * Proceeding history for a proposal, read **only** from timeline records.
     *
     * The timeline is the legal workflow history (architect Directive 5); the
     * business tables hold current state. This method touches no proceeding
     * row, so if the timeline could not answer the question it would visibly
     * fail rather than quietly fall back to reading state.
     *
     * @return Collection<int, ActivityTimeline>
     */
    public function historyFromTimeline(DpcProposal $proposal): Collection
    {
        return ActivityTimeline::query()
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->where('event', 'like', 'dpc_proceeding.%')
            // `occurred_at` has second granularity and one transition writes
            // more than one row, so the UUID is the tie-break that makes the
            // order deterministic across reads (M6 Phase 4 finding).
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();
    }

    /**
     * Every proposal must carry a UUID by the time a proceeding acts on it: an
     * approved result references the proposal permanently by UUID (architect
     * Directive 2, Phase 4), and the standing rule is that the UUID is the only
     * public identifier.
     *
     * The column is added and backfilled by migration. It is minted here for
     * any proposal created afterwards, **by direct update rather than through
     * the model**, because adding `HasUuids` to `DpcProposal` would modify a
     * frozen milestone. Flagged for a ruling: one line in M4 would make this
     * unnecessary.
     */
    private function ensureProposalUuid(DpcProposal $proposal): void
    {
        if ($proposal->uuid !== null) {
            return;
        }

        $uuid = (string) Str::uuid();
        DB::table('dpc_proposals')->where('id', $proposal->id)->update(['uuid' => $uuid]);
        $proposal->setAttribute('uuid', $uuid);
    }

    /**
     * A content fingerprint of the department's submission at the moment the
     * sitting opened — S31's evidence.
     *
     * **Deliberately distinct from M5's `proposal_version`**, which fingerprints
     * the *scoring input*. This one fingerprints the *whole submission*,
     * including loyalty and immovable-property returns: those do not affect a
     * score, but they decide whether an officer is held (`CIR`), and S31's
     * claim is about the department's entries as a whole, not merely the part
     * that feeds arithmetic. Two questions, two fingerprints.
     *
     * The two are also computed independently because `DpcCalculationService`
     * is frozen and its method is private — see the note raised with the
     * architect for Phase 1.
     */
    public function chartFingerprint(DpcProposal $proposal): string
    {
        $proposal->loadMissing([
            'evaluationRows.reservationCategory',
            'evaluationRows.crGrades',
            'evaluationRows.loyaltyFlags',
            'evaluationRows.propertyFlags',
        ]);

        $material = $proposal->evaluationRows
            ->map(fn ($row): array => [
                'sn' => $row->sn,
                'name' => $row->name,
                'dob' => $row->dob->toDateString(),
                'category' => $row->reservationCategory?->code,
                'tribal_years' => $row->tribal_area_service_years,
                'cr' => $row->crGrades->mapWithKeys(fn ($g): array => [$g->year => $g->grade])->sortKeys()->all(),
                'loyalty' => $row->loyaltyFlags->mapWithKeys(fn ($f): array => [$f->year => $f->flag])->sortKeys()->all(),
                'property' => $row->propertyFlags->mapWithKeys(fn ($f): array => [$f->year => $f->flag])->sortKeys()->all(),
            ])
            ->sortBy('sn')
            ->values()
            ->all();

        return hash('sha256', (string) json_encode($material));
    }

    /**
     * S31 says the DPC is conducted "at the prescribed date and time". Whether
     * the system *enforces* that is configuration (architect ruling, AQ-5):
     * the deck's sentence is about the validity of department data changes,
     * not about refusing a Commission that convenes early, and a strict
     * default would make the system undemonstrable.
     */
    private function assertScheduledTimeReached(DpcSchedule $schedule): void
    {
        if ((bool) config('dpc.proceeding.allow_open_before_scheduled_time', true)) {
            return;
        }

        if (now()->lt($schedule->scheduledFor())) {
            throw new WorkflowException(
                'This DPC is scheduled for '.$schedule->scheduledFor()->format('d/m/Y H:i')
                .' and cannot be opened before then.'
            );
        }
    }

    /**
     * Holding the Member role is not the same as being *this* DPC's member.
     * The workflow config gates the role; this gates the individual, and it is
     * checked here as well as in the policy so a non-HTTP caller is bound too.
     *
     * A Chairman who wishes to conduct a DPC re-allots it to themselves first
     * (architect ruling, AQ-6) — that keeps exactly one accountable actor on
     * the record, and re-allotment already exists and records its reason.
     */
    private function assertAllottee(DpcProposal $proposal, User $member): DpcAllotment
    {
        $allotment = $this->allotments->current($proposal);

        if ($allotment === null) {
            throw new WorkflowException('This DPC has not been allotted yet.');
        }

        if ($allotment->allotted_to !== $member->id) {
            throw new WorkflowException(
                'This DPC is allotted to another member. A Chairman who wishes to conduct it must re-allot it '
                .'to themselves first.'
            );
        }

        return $allotment;
    }
}
