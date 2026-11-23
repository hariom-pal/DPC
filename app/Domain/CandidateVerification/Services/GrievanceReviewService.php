<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\GrievanceDecision;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\GrievanceCorrection;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\CandidateVerification\Models\VerificationSnapshot;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The AH's review of the candidates' grievances and the AO's corrections (2026-11-19).
 *
 * Each grievance is decided on its own: without a correction, or after the AH opens
 * that candidate's section — and only that — for the AO, who corrects it through the
 * ordinary Employee Details and sends it back. A correction never decides a
 * grievance: the AH does, with a remark. The corrected record is kept as a new
 * snapshot beside the signed one, which is never rewritten, and the candidate is told.
 * Once every grievance is decided and no correction is open, the DPC goes to the
 * Secretary.
 */
final class GrievanceReviewService
{
    public function __construct(
        private readonly ProposalAllotmentService $allotments,
        private readonly ActivityTimelineService $timeline,
        private readonly VerificationNotifier $notifier,
        private readonly VerificationRoutingService $routing,
        private readonly CandidateRecordView $view,
        private readonly WorkflowEngine $engine,
    ) {}

    /**
     * Whether the AH may act on this DPC's grievances now (2026-11-23): in the grievance
     * review, and — as each is submitted — while the candidate verification is still
     * open, so the AH need not wait for the deadline.
     */
    public function actionable(DpcProposal $proposal): bool
    {
        if ($proposal->status === DpcProposalStatus::GrievanceReview) {
            return true;
        }

        return $proposal->status === DpcProposalStatus::CandidateVerification
            && VerificationCycle::query()->where('dpc_proposal_id', $proposal->id)->where('status', CycleStatus::Open->value)->exists();
    }

    /** Whether an AO correction of this officer's Employee Details section is open now. */
    public function correctionOpen(DpcEvaluationRow $row, ?string $employeeSection = null): bool
    {
        return GrievanceCorrection::query()->where('dpc_evaluation_row_id', $row->id)->where('status', GrievanceCorrection::OPEN)->get()
            ->contains(fn (GrievanceCorrection $c): bool => $employeeSection === null || $c->section->employeeSection() === $employeeSection);
    }

    /** @throws WorkflowException */
    public function resolve(Grievance $grievance, GrievanceDecision $decision, string $remark, User $ah, Request $request): Grievance
    {
        $resolved = DB::transaction(function () use ($grievance, $decision, $remark, $ah): Grievance {
            [$locked, $proposal] = $this->lockForAh($grievance, $ah);

            if (! in_array($locked->status, [GrievanceStatus::Submitted, GrievanceStatus::ReturnedFromAo], true)) {
                throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
            }
            $this->assertRemark($remark);

            $locked->forceFill([
                'status' => GrievanceStatus::Resolved->value,
                'decision' => $decision->value,
                'resolution_remark' => trim($remark),
                'resolved_by' => $ah->id,
                'resolved_at' => now(),
            ])->save();

            $this->timeline->record('dpc_proposal.grievance_resolved', $proposal, sprintf(
                'Grievance on %s of %s decided: %s', $locked->section->label(), $locked->participant->row->name, $decision->label(),
            ), ['grievance' => $locked->uuid, 'decision' => $decision->value, 'remark' => trim($remark)], $ah);

            return $locked;
        });

        $this->notifier->toParticipant($resolved->participant, 'grievance_resolved', [
            'dpc' => CandidateRecordView::reference($resolved->participant->row->proposal),
            'section' => $resolved->section->label(),
            'url' => route('candidate-verification.login'),
        ], $resolved->uuid);

        $this->routeWhenAllResolved($resolved->cycle, $ah, $request);

        return $resolved;
    }

    /**
     * Open the candidate's section — this one only — for the AO.
     *
     * @throws WorkflowException
     */
    public function sendToAo(Grievance $grievance, string $instructions, User $ah): GrievanceCorrection
    {
        $correction = DB::transaction(function () use ($grievance, $instructions, $ah): GrievanceCorrection {
            [$locked, $proposal] = $this->lockForAh($grievance, $ah);

            if (! in_array($locked->status, [GrievanceStatus::Submitted, GrievanceStatus::ReturnedFromAo], true)) {
                throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
            }
            $this->assertRemark($instructions);

            $correction = GrievanceCorrection::query()->create([
                'dpc_grievance_id' => $locked->id,
                'dpc_evaluation_row_id' => $locked->participant->dpc_evaluation_row_id,
                'section' => $locked->section->value,
                'instructions' => trim($instructions),
                'assigned_by' => $ah->id,
                'assigned_at' => now(),
                'status' => GrievanceCorrection::OPEN,
            ]);
            $locked->forceFill(['status' => GrievanceStatus::SentToAo->value])->save();

            $this->timeline->record('dpc_proposal.grievance_sent_to_ao', $proposal, sprintf(
                '%s of %s opened for the AO to correct', $locked->section->label(), $locked->participant->row->name,
            ), ['grievance' => $locked->uuid, 'section' => $locked->section->value, 'instructions' => trim($instructions)], $ah);

            return $correction;
        });

        $grievance = $correction->grievance;
        $proposal = $grievance->participant->row->proposal;
        $this->notifier->toUsers($grievance->cycle, $this->routing->allotted($proposal, Role::AuthorizedOfficer), 'correction_assigned', [
            'dpc' => CandidateRecordView::reference($proposal), 'candidate' => $grievance->participant->row->name,
            'section' => $grievance->section->label(), 'url' => route('dpc-proposals.grievances.index', $proposal),
        ], 'correction:'.$correction->id);

        return $correction;
    }

    /**
     * The AO sends the correction back; the section closes again.
     *
     * @throws WorkflowException
     */
    public function submitCorrection(GrievanceCorrection $correction, string $remarks, User $ao): GrievanceCorrection
    {
        $done = DB::transaction(function () use ($correction, $remarks, $ao): GrievanceCorrection {
            /** @var GrievanceCorrection $locked */
            $locked = GrievanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $grievance = $locked->grievance;
            $proposal = $grievance->participant->row->proposal;

            if (! $this->actionable($proposal) || ! $this->allotments->isAllotted($ao, $proposal, Role::AuthorizedOfficer)) {
                throw new WorkflowException((string) __('candidate.grievance.errors.not_yours'));
            }
            if ($locked->status !== GrievanceCorrection::OPEN) {
                throw new WorkflowException((string) __('candidate.grievance.errors.correction_closed'));
            }
            $this->assertRemark($remarks);

            $locked->forceFill(['status' => GrievanceCorrection::SUBMITTED, 'ao_remarks' => trim($remarks), 'submitted_by' => $ao->id, 'submitted_at' => now()])->save();
            $grievance->forceFill(['status' => GrievanceStatus::ReturnedFromAo->value])->save();

            // The record as corrected, kept beside the signed one — never over it.
            $participant = $grievance->participant;
            $record = $this->view->all($participant->row->fresh() ?? $participant->row);
            VerificationSnapshot::query()->create([
                'dpc_verification_participant_id' => $participant->id,
                'version' => (int) VerificationSnapshot::query()->where('dpc_verification_participant_id', $participant->id)->max('version') + 1,
                'reason' => 'corrected_after_grievance',
                'dpc_grievance_id' => $grievance->id,
                'snapshot' => ['record' => $record, 'grievance' => $grievance->uuid],
                'sha256' => VerificationSnapshot::hashOf($record),
                'created_by' => $ao->id,
            ]);

            $this->timeline->record('dpc_proposal.grievance_correction_submitted', $proposal, sprintf(
                'Correction of %s of %s submitted to the AH', $grievance->section->label(), $participant->row->name,
            ), ['grievance' => $grievance->uuid, 'remarks' => trim($remarks)], $ao);

            return $locked;
        });

        $grievance = $done->grievance;
        $participant = $grievance->participant;
        $proposal = $participant->row->proposal;
        $values = ['dpc' => CandidateRecordView::reference($proposal), 'candidate' => $participant->row->name, 'section' => $grievance->section->label(), 'url' => route('dpc-proposals.grievances.index', $proposal)];
        $this->notifier->toUsers($grievance->cycle, $this->routing->allotted($proposal, Role::AdministrativeHead), 'correction_submitted', $values, 'correction:'.$done->id);
        $this->notifier->toParticipant($participant, 'record_corrected', [...$values, 'url' => route('candidate-verification.login')], 'correction:'.$done->id);

        return $done;
    }

    /**
     * What still keeps the DPC from the Secretary.
     *
     * @return list<string>
     */
    public function secretaryBlockers(VerificationCycle $cycle): array
    {
        $blockers = [];
        $open = Grievance::query()->where('dpc_verification_cycle_id', $cycle->id)->whereIn('status', [GrievanceStatus::Submitted->value, GrievanceStatus::SentToAo->value, GrievanceStatus::ReturnedFromAo->value])->count();
        if ($open > 0) {
            $blockers[] = (string) __('candidate.grievance.errors.open', ['count' => $open]);
        }
        $corrections = GrievanceCorrection::query()->where('status', GrievanceCorrection::OPEN)->whereHas('grievance', fn ($q) => $q->where('dpc_verification_cycle_id', $cycle->id))->count();
        if ($corrections > 0) {
            $blockers[] = (string) __('candidate.grievance.errors.corrections_open', ['count' => $corrections]);
        }

        return $blockers;
    }

    private function routeWhenAllResolved(VerificationCycle $cycle, User $ah, Request $request): void
    {
        $moved = DB::transaction(function () use ($cycle, $ah, $request): ?DpcProposal {
            /** @var VerificationCycle $locked */
            $locked = VerificationCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            /** @var DpcProposal $proposal */
            $proposal = DpcProposal::query()->whereKey($locked->dpc_proposal_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CycleStatus::GrievanceReview || $proposal->status !== DpcProposalStatus::GrievanceReview || $this->secretaryBlockers($locked) !== []) {
                return null;
            }

            $def = $this->engine->assertAllowed('dpc_proposal', 'grievances_resolved', $proposal->status->value, $ah);
            $proposal->forceFill(['status' => DpcProposalStatus::PendingSecretary->value])->save();
            $locked->forceFill(['status' => CycleStatus::Completed->value, 'routed_to' => 'secretary', 'routed_at' => now()])->save();
            $this->engine->record($proposal, 'dpc_proposal', 'grievances_resolved', DpcProposalStatus::GrievanceReview->value, $def, $ah, $request,
                (string) __('candidate.grievance.all_resolved'), null, $proposal->department_id,
                notifyUserIds: User::role(Role::Secretary->value)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all());

            return $proposal;
        });

        if ($moved !== null) {
            $this->routing->notifySecretary($cycle, $moved);
        }
    }

    /**
     * @return array{0: Grievance, 1: DpcProposal}
     *
     * @throws WorkflowException
     */
    private function lockForAh(Grievance $grievance, User $ah): array
    {
        /** @var Grievance $locked */
        $locked = Grievance::query()->whereKey($grievance->id)->lockForUpdate()->firstOrFail();
        /** @var VerificationParticipant $participant */
        $participant = $locked->participant;
        $proposal = $participant->row->proposal;

        if (! $this->actionable($proposal) || ! $this->allotments->isAllotted($ah, $proposal, Role::AdministrativeHead)) {
            throw new WorkflowException((string) __('candidate.grievance.errors.not_yours'));
        }

        return [$locked, $proposal];
    }

    /** @throws WorkflowException */
    private function assertRemark(string $remark): void
    {
        if (trim($remark) === '') {
            throw new WorkflowException((string) __('candidate.grievance.errors.remark_required'));
        }
    }
}
