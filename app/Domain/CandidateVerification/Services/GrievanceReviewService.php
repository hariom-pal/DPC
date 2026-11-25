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
use App\Domain\Signature\Services\SignatureService;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The AH's review of the candidates' grievances and the AO's corrections (2026-11-19;
 * the flow made one step at a time, 2026-11-24):
 *
 *  1. The AH decides each grievance. **Upheld** — the candidate is right — opens that
 *     candidate's section for the AO with the AH's instructions. **Not upheld** — the
 *     record stands — closes it with its reason, eSigned.
 *  2. The AO corrects each opened section and sends the candidate's corrections back.
 *  3. The AH approves the candidate's corrections with the eSign (or sends one back to
 *     the AO). Only then is the grievance resolved, and the candidate told.
 *
 * Earlier (2026-11-19):
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

    /**
     * The grievances waiting on this user, DPC by DPC, for the dashboard (2026-11-24):
     * for the AH those to decide and corrections to approve, for the AO the sections
     * to correct. Only the DPCs allotted to them, and only those with something open.
     *
     * @return list<array{proposal: DpcProposal, to_decide: int, to_approve: int, with_ao: int, to_correct: int, ah: bool, ao: bool}>
     */
    public function waitingOn(User $user): array
    {
        $isAh = $user->hasRole(Role::AdministrativeHead->value);
        $isAo = $user->hasRole(Role::AuthorizedOfficer->value);
        if (! $isAh && ! $isAo) {
            return [];
        }

        $open = [GrievanceStatus::Submitted->value, GrievanceStatus::SentToAo->value, GrievanceStatus::ReturnedFromAo->value];
        $proposals = $this->allotments->scopeTo(DpcProposal::query(), $user)
            ->whereIn('id', VerificationCycle::query()->whereIn('id', Grievance::query()->whereIn('status', $open)->select('dpc_verification_cycle_id'))->select('dpc_proposal_id'))
            ->orderByDesc('id')->get();

        $entries = [];
        foreach ($proposals as $proposal) {
            $counts = Grievance::query()->whereHas('cycle', fn ($q) => $q->where('dpc_proposal_id', $proposal->id))
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
            $ah = $isAh && $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead);
            $ao = $isAo && $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer);
            $entry = [
                'proposal' => $proposal,
                'to_decide' => (int) ($counts[GrievanceStatus::Submitted->value] ?? 0),
                'to_approve' => (int) ($counts[GrievanceStatus::ReturnedFromAo->value] ?? 0),
                'with_ao' => (int) ($counts[GrievanceStatus::SentToAo->value] ?? 0),
                'to_correct' => (int) ($counts[GrievanceStatus::SentToAo->value] ?? 0),
                'ah' => $ah,
                'ao' => $ao,
            ];
            if (($ah && $entry['to_decide'] + $entry['to_approve'] > 0) || ($ao && $entry['to_correct'] > 0)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Whether an AO correction of this officer's Employee Details section is open now. */
    public function correctionOpen(DpcEvaluationRow $row, ?string $employeeSection = null): bool
    {
        return GrievanceCorrection::query()->where('dpc_evaluation_row_id', $row->id)->where('status', GrievanceCorrection::OPEN)->get()
            ->contains(fn (GrievanceCorrection $c): bool => $employeeSection === null || $c->section->employeeSection() === $employeeSection);
    }

    /**
     * Upheld (2026-11-24): the candidate is right, so their section opens for the AO
     * with the AH's instructions. Nothing is resolved yet; the AH approves the
     * correction when the AO sends it back.
     *
     * @throws WorkflowException
     */
    public function uphold(Grievance $grievance, string $instructions, User $ah): GrievanceCorrection
    {
        if ($grievance->status !== GrievanceStatus::Submitted) {
            throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
        }

        return $this->openForAo($grievance, $instructions, $ah, GrievanceDecision::Upheld);
    }

    /**
     * Revised (2026-11-24): the candidate's request is not right, but the original record
     * shows something else. To the AO with the AH's suggestion, to correct the record to
     * the original; resolved when the AH approves it. The decision is final — a
     * grievance is raised once.
     *
     * @throws WorkflowException
     */
    public function revise(Grievance $grievance, string $suggestion, User $ah): GrievanceCorrection
    {
        if ($grievance->status !== GrievanceStatus::Submitted) {
            throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
        }

        return $this->openForAo($grievance, $suggestion, $ah, GrievanceDecision::Revised);
    }

    /**
     * Not upheld (2026-11-24): the record stands. Closed with its reason, eSigned by the
     * AH; the candidate sees the reason.
     *
     * @throws WorkflowException
     */
    public function notUphold(Grievance $grievance, string $reason, User $ah, Request $request): Grievance
    {
        $resolved = DB::transaction(function () use ($grievance, $reason, $ah): Grievance {
            [$locked, $proposal] = $this->lockForAh($grievance, $ah);

            if ($locked->status !== GrievanceStatus::Submitted) {
                throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
            }
            $this->assertRemark($reason);

            $row = $locked->participant->row;
            app(SignatureService::class)->signVerified($ah, $locked, implode("\n", [
                'GRIEVANCE NOT UPHELD',
                'DPC: '.CandidateRecordView::reference($proposal),
                "Candidate: {$row->name} (S.N. {$row->sn})",
                'Section: '.$locked->section->label().($locked->related_year ? ' '.$locked->related_year : ''),
                'Reason: '.trim($reason),
                "Decided by: {$ah->name}",
                'Date: '.now()->toDateTimeString(),
            ]), "grievance-{$locked->uuid}-not-upheld.txt", ['action' => 'grievance_not_upheld']);

            $this->close($locked, GrievanceDecision::NotUpheld, $reason, $ah, $proposal);

            return $locked;
        });

        $this->toldResolved($resolved);
        $this->routeWhenAllResolved($resolved->cycle, $ah, $request);

        return $resolved;
    }

    /**
     * The AH approves a candidate's corrections with the eSign (2026-11-24): every
     * grievance of theirs the AO has sent back is resolved — upheld, the record
     * corrected — and the candidate is told.
     *
     * @return int how many were resolved
     *
     * @throws WorkflowException
     */
    public function approveCandidate(VerificationParticipant $participant, string $remark, User $ah, Request $request): int
    {
        $resolved = DB::transaction(function () use ($participant, $remark, $ah): array {
            $this->assertRemark($remark);
            $grievances = Grievance::query()->where('dpc_verification_participant_id', $participant->id)
                ->where('status', GrievanceStatus::ReturnedFromAo->value)->orderBy('id')->lockForUpdate()->get();
            if ($grievances->isEmpty()) {
                throw new WorkflowException((string) __('candidate.grievance.errors.nothing_to_approve'));
            }

            $proposal = null;
            foreach ($grievances as $grievance) {
                [, $proposal] = $this->lockForAh($grievance, $ah);
            }

            $row = $participant->row;
            app(SignatureService::class)->signVerified($ah, $participant, implode("\n", [
                'GRIEVANCE CORRECTIONS APPROVED',
                'DPC: '.CandidateRecordView::reference($proposal),
                "Candidate: {$row->name} (S.N. {$row->sn})",
                ...$grievances->map(fn (Grievance $g): string => '- '.$g->section->label().($g->related_year ? ' '.$g->related_year : '').': '.$g->remark)->all(),
                'Remark: '.trim($remark),
                "Approved by: {$ah->name}",
                'Date: '.now()->toDateTimeString(),
            ]), "grievances-{$participant->uuid}-approved.txt", ['action' => 'grievance_corrections_approved']);

            foreach ($grievances as $grievance) {
                // As decided: Upheld, or Revised to the original record.
                $this->close($grievance, $grievance->decision ?? GrievanceDecision::Upheld, $remark, $ah, $proposal);
            }

            return $grievances->all();
        });

        foreach ($resolved as $grievance) {
            $this->toldResolved($grievance);
        }
        $this->routeWhenAllResolved($participant->cycle, $ah, $request);

        return count($resolved);
    }

    private function close(Grievance $grievance, GrievanceDecision $decision, string $remark, User $ah, DpcProposal $proposal): void
    {
        $grievance->forceFill([
            'status' => GrievanceStatus::Resolved->value,
            'decision' => $decision->value,
            'resolution_remark' => trim($remark),
            'resolved_by' => $ah->id,
            'resolved_at' => now(),
        ])->save();

        $this->timeline->record('dpc_proposal.grievance_resolved', $proposal, sprintf(
            'Grievance on %s of %s decided: %s', $grievance->section->label(), $grievance->participant->row->name, $decision->label(),
        ), ['grievance' => $grievance->uuid, 'decision' => $decision->value, 'remark' => trim($remark), 'signed' => true], $ah);
    }

    /** The candidate hears the decision — and of the correction made for it — once resolved. */
    private function toldResolved(Grievance $resolved): void
    {
        $proposal = $resolved->participant->row->proposal;

        // Revised: one message that says the request was not accepted and the record was corrected to the original.
        if ($resolved->decision === GrievanceDecision::Revised) {
            $this->notifier->toParticipant($resolved->participant, 'grievance_revised', [
                'dpc' => CandidateRecordView::reference($proposal),
                'section' => $resolved->section->label(),
                'url' => route('candidate-verification.login'),
            ], $resolved->uuid);

            return;
        }

        $correction = GrievanceCorrection::query()->where('dpc_grievance_id', $resolved->id)->where('status', GrievanceCorrection::SUBMITTED)->latest('id')->first();
        if ($correction !== null) {
            $this->notifier->toParticipant($resolved->participant, 'record_corrected', [
                'dpc' => CandidateRecordView::reference($proposal),
                'candidate' => $resolved->participant->row->name,
                'section' => $resolved->section->label(),
                'url' => route('candidate-verification.login'),
            ], 'correction:'.$correction->id);
        }

        $this->notifier->toParticipant($resolved->participant, 'grievance_resolved', [
            'dpc' => CandidateRecordView::reference($proposal),
            'section' => $resolved->section->label(),
            'url' => route('candidate-verification.login'),
        ], $resolved->uuid);
    }

    /**
     * The AO's correction is not yet right (2026-11-24): back to the AO, with what
     * still needs doing.
     *
     * @throws WorkflowException
     */
    public function sendBack(Grievance $grievance, string $instructions, User $ah): GrievanceCorrection
    {
        if ($grievance->status !== GrievanceStatus::ReturnedFromAo) {
            throw new WorkflowException((string) __('candidate.grievance.errors.not_decidable'));
        }

        return $this->openForAo($grievance, $instructions, $ah, null);
    }

    /**
     * Open the candidate's section — this one only — for the AO.
     *
     * @throws WorkflowException
     */
    /** @param  GrievanceDecision|null  $decision  Upheld or Revised when deciding; null when sent back */
    private function openForAo(Grievance $grievance, string $instructions, User $ah, ?GrievanceDecision $decision): GrievanceCorrection
    {
        $correction = DB::transaction(function () use ($grievance, $instructions, $ah, $decision): GrievanceCorrection {
            [$locked, $proposal] = $this->lockForAh($grievance, $ah);

            $expected = $decision !== null ? GrievanceStatus::Submitted : GrievanceStatus::ReturnedFromAo;
            if ($locked->status !== $expected) {
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
            // Upheld or Revised is the AH's decision now; the grievance resolves when the correction is approved.
            $locked->forceFill(['status' => GrievanceStatus::SentToAo->value, 'decision' => ($decision ?? $locked->decision ?? GrievanceDecision::Upheld)->value])->save();

            $this->timeline->record($decision === null ? 'dpc_proposal.grievance_sent_back' : 'dpc_proposal.grievance_'.$decision->value, $proposal, sprintf(
                match ($decision) {
                    GrievanceDecision::Upheld => 'Grievance on %s of %s upheld: opened for the AO to correct',
                    GrievanceDecision::Revised => 'Grievance on %s of %s revised: opened for the AO to correct to the original record',
                    default => 'Correction of %s of %s sent back to the AO',
                },
                $locked->section->label(), $locked->participant->row->name,
            ), ['grievance' => $locked->uuid, 'section' => $locked->section->value, 'instructions' => trim($instructions)], $ah);

            return $correction;
        });

        $grievance = $correction->grievance;
        $proposal = $grievance->participant->row->proposal;
        $this->notifier->toUsers($grievance->cycle, $this->routing->allotted($proposal, Role::AuthorizedOfficer), 'correction_assigned', [
            'dpc' => CandidateRecordView::reference($proposal), 'candidate' => $grievance->participant->row->name,
            'section' => $grievance->section->label(), 'url' => route('grievances.corrections'),
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
            // The section as it stood before this correction — the last version kept, the
            // signed one at first — and what changed in it, value by value (2026-11-25).
            $previous = VerificationSnapshot::query()->where('dpc_verification_participant_id', $participant->id)->latest('version')->first();
            $before = (array) (($previous?->snapshot['record'] ?? [])[$grievance->section->value] ?? []);
            $changes = CandidateRecordView::changes($before, (array) ($record[$grievance->section->value] ?? []));
            VerificationSnapshot::query()->create([
                'dpc_verification_participant_id' => $participant->id,
                'version' => (int) ($previous->version ?? 0) + 1,
                'reason' => 'corrected_after_grievance',
                'dpc_grievance_id' => $grievance->id,
                'snapshot' => ['record' => $record, 'grievance' => $grievance->uuid, 'changes' => $changes],
                'sha256' => VerificationSnapshot::hashOf($record),
                'created_by' => $ao->id,
            ]);

            $this->timeline->record('dpc_proposal.grievance_correction_submitted', $proposal, sprintf(
                'Correction of %s of %s submitted to the AH%s', $grievance->section->label(), $participant->row->name,
                $changes === [] ? ' (no value changed)' : ': '.implode('; ', $changes),
            ), ['grievance' => $grievance->uuid, 'remarks' => trim($remarks), 'changes' => $changes], $ao);

            return $locked;
        });

        $grievance = $done->grievance;
        $participant = $grievance->participant;
        $proposal = $participant->row->proposal;
        $values = ['dpc' => CandidateRecordView::reference($proposal), 'candidate' => $participant->row->name, 'section' => $grievance->section->label(), 'url' => route('dpc-proposals.grievances.index', $proposal)];
        $this->notifier->toUsers($grievance->cycle, $this->routing->allotted($proposal, Role::AdministrativeHead), 'correction_submitted', $values, 'correction:'.$done->id);
        // The candidate hears of the correction when the AH decides the grievance (2026-11-24), not before.

        return $done;
    }

    /**
     * The AO sends a candidate's corrections back to the AH together (2026-11-24): every
     * section opened for that candidate, with one remark.
     *
     * @return int how many were sent back
     *
     * @throws WorkflowException
     */
    public function submitCandidate(VerificationParticipant $participant, string $remarks, User $ao): int
    {
        $corrections = GrievanceCorrection::query()->where('status', GrievanceCorrection::OPEN)
            ->whereHas('grievance', fn ($q) => $q->where('dpc_verification_participant_id', $participant->id))
            ->orderBy('id')->get();
        if ($corrections->isEmpty()) {
            throw new WorkflowException((string) __('candidate.grievance.errors.nothing_to_submit'));
        }

        return DB::transaction(function () use ($corrections, $remarks, $ao): int {
            foreach ($corrections as $correction) {
                $this->submitCorrection($correction, $remarks, $ao);
            }

            return $corrections->count();
        });
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
