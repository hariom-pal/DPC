<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
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

        if ($decision === DpcCommitteeDecision::RETURNED && $sections === []) {
            throw new WorkflowException(__('dpc.committee_decision.sections_required'));
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

            $summary = collect($sections)->map(fn (string $remark, string $section): string => __('dpc.committee_decision.sections.'.$section).': '.trim($remark))->implode('; ');

            $locked->forceFill([
                'status' => DpcProposalStatus::Returned->value,
                'returned_at' => now(),
                'return_remarks' => $summary,
                'committee_returned_at' => now(),
                'committee_return' => ['sections' => array_map('trim', $sections), 'by' => $member->id, 'member' => $name, 'decision' => $record->uuid],
            ])->save();

            $this->revisions->recordReturn($locked, $member, $summary, 'committee');

            // The committee holds no workflow role (a seat, not an office), so the
            // transition is defined here and guarded by the policy, not the engine.
            $this->engine->record($locked, 'dpc_proposal', 'committee_return', DpcProposalStatus::Approved->value, [
                'from' => [DpcProposalStatus::Approved->value], 'to' => DpcProposalStatus::Returned->value,
                'role' => 'committee', 'signature' => false, 'notify' => ['authorized_officer', 'administrative_head'],
            ], $member, $request, $summary, null, $locked->department_id, ['sections' => array_keys($sections)]);

            return $record;
        });
    }

    /** The AO sends the corrected DPC straight back to the committee (point 3). */
    public function resubmit(DpcProposal $proposal, User $ao, Request $request): void
    {
        DB::transaction(function () use ($proposal, $ao, $request): void {
            /** @var DpcProposal $locked */
            $locked = DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DpcProposalStatus::Returned || $locked->committee_returned_at === null) {
                throw new WorkflowException(__('dpc.committee_decision.no_open_return'));
            }

            $sections = (array) ($locked->committee_return['sections'] ?? []);

            $locked->forceFill([
                'status' => DpcProposalStatus::Approved->value,
                'committee_returned_at' => null,
                'committee_return' => null,
            ])->save();

            DpcCommitteeDecision::query()->where('dpc_proposal_id', $locked->id)
                ->where('decision', DpcCommitteeDecision::RETURNED)->whereNull('resubmitted_at')
                ->update(['resubmitted_at' => now()]);

            $this->revisions->recordApproval($locked);

            $committee = DpcCommitteeMember::query()->where('dpc_proposal_id', $locked->id)->serving()->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

            $this->engine->record($locked, 'dpc_proposal', 'committee_resubmit', DpcProposalStatus::Returned->value, [
                'from' => [DpcProposalStatus::Returned->value], 'to' => DpcProposalStatus::Approved->value,
                'role' => 'authorized_officer', 'signature' => false, 'notify' => [],
            ], $ao, $request, null, null, $locked->department_id, ['sections' => array_keys($sections)], notifyUserIds: $committee);
        });
    }
}
