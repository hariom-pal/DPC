<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Services\SittingMeetingService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MoM of 16 November 2026, points 2 and 3: the DPC Committee's decision on a DPC it
 * has evaluated.
 *
 * A member agrees, or returns the DPC to the Authorized Officer naming one or more
 * sections, each with its remark. A return sends the DPC straight to the AO, who may
 * change only those sections and resubmits it straight to the committee: the
 * Administrative Head, the Secretary and the earlier approvals do not run again.
 * Every decision, return and resubmission is kept.
 */
final class CommitteeDecisionService
{
    /** The sections a member may return, as Employee Details names them. */
    public const SECTIONS = ['basic', 'cr', 'property', 'ph', 'vigilance', 'declarations', 'documents'];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly DpcRevisionService $revisions,
    ) {}

    /**
     * @param  array<string, string>  $sections  section => remark, for a return
     */
    public function decide(DpcProposal $proposal, User $member, string $decision, ?string $remarks, array $sections, Request $request): DpcCommitteeDecision
    {
        if (! in_array($decision, [DpcCommitteeDecision::AGREED, DpcCommitteeDecision::RETURNED], true)) {
            throw new WorkflowException(__('dpc.committee_decision.invalid'));
        }

        $sections = array_filter(
            array_intersect_key($sections, array_flip(self::SECTIONS)),
            static fn (?string $remark): bool => trim((string) $remark) !== '',
        );

        // A rejection is a remark, not a choice of sections: the Secretary marks those,
        // candidate by candidate (2026-11-18).
        if ($decision === DpcCommitteeDecision::RETURNED && trim((string) $remarks) === '') {
            throw new WorkflowException(__('dpc.committee_decision.remarks_required'));
        }

        return DB::transaction(function () use ($proposal, $member, $decision, $remarks, $sections, $request): DpcCommitteeDecision {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DpcProposalStatus::Approved) {
                throw new WorkflowException(__('dpc.committee_decision.not_at_committee'));
            }

            $name = (string) (DpcCommitteeMember::query()->where('dpc_proposal_id', $locked->id)->serving()->where('user_id', $member->id)->value('name') ?? $member->name);

            $record = DpcCommitteeDecision::query()->create([
                'dpc_proposal_id' => $locked->id,
                'user_id' => $member->id,
                'member_name' => $name,
                'decision' => $decision,
                'remarks' => $remarks === null || trim($remarks) === '' ? null : trim($remarks),
                'sections' => $decision === DpcCommitteeDecision::RETURNED ? array_map('trim', $sections) : null,
                'decided_at' => now(),
            ]);

            if ($decision === DpcCommitteeDecision::AGREED) {
                return $record;
            }

            $remark = trim((string) $remarks);

            // Rejected by the DPC Chairman: to the Secretary, who marks the candidates and
            // sections at fault; then the AH opens them for the AO (2026-11-18).
            $locked->forceFill([
                'status' => DpcProposalStatus::PendingSecretary->value,
                'committee_returned_at' => null,
                'committee_return' => ['stage' => 'secretary', 'remarks' => $remark, 'by' => $member->id, 'member' => $name, 'decision' => $record->uuid, 'candidates' => []],
            ])->save();

            $this->revisions->recordReturn($locked, $member, $remark, 'committee');

            $this->engine->record($locked, 'dpc_proposal', 'committee_reject', DpcProposalStatus::Approved->value, [
                'from' => [DpcProposalStatus::Approved->value], 'to' => DpcProposalStatus::PendingSecretary->value,
                'role' => 'committee', 'signature' => false, 'notify' => [],
            ], $member, $request, $remark, null, $locked->department_id, [], notifyUserIds: $this->secretaryIds());

            return $record;
        });
    }

    /**
     * The Secretary marks, candidate by candidate, the sections a rejected DPC is wrong
     * in, with a remark each, and sends it to the AH (2026-11-18).
     *
     * @param  array<int, array<string, string>>  $candidates  row id => [section => remark]
     */
    public function markRejection(DpcProposal $proposal, User $secretary, array $candidates, Request $request): void
    {
        $candidates = array_filter(array_map(
            fn (array $sections): array => array_filter(array_map('trim', array_intersect_key($sections, array_flip(self::SECTIONS))), fn (string $remark): bool => $remark !== ''),
            $candidates,
        ));

        if ($candidates === []) {
            throw new WorkflowException(__('dpc.committee_rejection.nothing_marked'));
        }

        DB::transaction(function () use ($proposal, $secretary, $candidates, $request): void {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DpcProposalStatus::PendingSecretary || $locked->committeeReturnStage() !== 'secretary') {
                throw new WorkflowException(__('dpc.committee_rejection.not_with_secretary'));
            }

            $own = $locked->evaluationRows()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $candidates = array_intersect_key($candidates, array_flip($own));

            $locked->forceFill([
                'status' => DpcProposalStatus::Submitted->value,
                'committee_return' => ['stage' => 'ah', 'candidates' => $candidates, 'secretary' => $secretary->id] + (array) $locked->committee_return,
            ])->save();
            // `+` keeps the left's keys: the stage and the candidates are the new ones.

            $this->engine->record($locked, 'dpc_proposal', 'secretary_marks_rejection', DpcProposalStatus::PendingSecretary->value, [
                'from' => [DpcProposalStatus::PendingSecretary->value], 'to' => DpcProposalStatus::Submitted->value,
                'role' => 'secretary', 'signature' => false, 'notify' => ['administrative_head'],
            ], $secretary, $request, null, null, $locked->department_id, ['candidates' => $candidates]);
        });
    }

    /** The AH opens the sections the Secretary marked for the AO to correct (2026-11-18). */
    public function openForAo(DpcProposal $proposal, User $ah, Request $request): void
    {
        DB::transaction(function () use ($proposal, $ah, $request): void {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DpcProposalStatus::Submitted || $locked->committeeReturnStage() !== 'ah') {
                throw new WorkflowException(__('dpc.committee_rejection.not_with_ah'));
            }

            $names = $locked->evaluationRows()->pluck('name', 'id');
            $summary = collect($locked->committeeReturnCandidates())
                ->map(fn (array $sections, int $rowId): string => ($names[$rowId] ?? '#'.$rowId).' — '.collect($sections)->map(fn (string $remark, string $section): string => __('dpc.committee_decision.sections.'.$section).': '.$remark)->implode('; '))
                ->implode(' | ');

            $locked->forceFill([
                'status' => DpcProposalStatus::Returned->value,
                'returned_at' => now(),
                'return_remarks' => $summary,
                'committee_returned_at' => now(),
                'committee_return' => ['stage' => 'ao'] + (array) $locked->committee_return,
            ])->save();

            $this->engine->record($locked, 'dpc_proposal', 'ah_opens_sections', DpcProposalStatus::Submitted->value, [
                'from' => [DpcProposalStatus::Submitted->value], 'to' => DpcProposalStatus::Returned->value,
                'role' => 'administrative_head', 'signature' => false, 'notify' => ['authorized_officer'],
            ], $ah, $request, $summary, null, $locked->department_id);
        });
    }

    /** @return list<int> */
    private function secretaryIds(): array
    {
        return User::role(Role::Secretary->value)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The AO sends the corrected DPC on: to the AH, then the Secretary, and so back to
     * the committee (2026-11-18; until then it went straight to the committee).
     */
    public function resubmit(DpcProposal $proposal, User $ao, Request $request): void
    {
        DB::transaction(function () use ($proposal, $ao, $request): void {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DpcProposalStatus::Returned || $locked->committee_returned_at === null) {
                throw new WorkflowException(__('dpc.committee_decision.no_open_return'));
            }

            $sections = array_flip((array) $locked->committeeReturnSections());
            $candidates = array_keys($locked->committeeReturnCandidates());

            $locked->forceFill([
                'status' => DpcProposalStatus::Submitted->value,
                'submitted_at' => now(),
                'committee_returned_at' => null,
                'committee_return' => null,
            ])->save();

            DpcCommitteeDecision::query()->where('dpc_proposal_id', $locked->id)
                ->where('decision', DpcCommitteeDecision::RETURNED)->whereNull('resubmitted_at')
                ->update(['resubmitted_at' => now()]);

            // What came back is OK'd afresh: no OK on the earlier submission carries
            // forward unverified (2026-11-18).
            app(SittingMeetingService::class)->invalidateProposal($locked, 'resubmission', $ao);

            // A submission again, as any resubmission to the AH is.
            $this->revisions->recordSubmission($locked, $ao);

            $this->engine->record($locked, 'dpc_proposal', 'committee_resubmit', DpcProposalStatus::Returned->value, [
                'from' => [DpcProposalStatus::Returned->value], 'to' => DpcProposalStatus::Submitted->value,
                'role' => 'authorized_officer', 'signature' => false, 'notify' => ['administrative_head'],
            ], $ao, $request, null, null, $locked->department_id, ['sections' => array_keys($sections), 'candidates' => $candidates]);
        });
    }
}
