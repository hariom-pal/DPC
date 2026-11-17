<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where each DPC stands, whose move it is and by when (MoM of 16 November 2026,
 * points 5, 6, 7 and 8).
 *
 * The stage is read from the proposal's status — and, once the Secretary has
 * approved it, from whether the Chairman has allotted it to the committee. The
 * stage began when the proposal last entered its status (the workflow ledger), or,
 * for the committee, when the DPC was allotted. Its deadline is that moment plus the
 * days the CGPSC Administrator allows the stage; a stage with no days set has none.
 */
final class DpcStageService
{
    /** The statuses a DPC still moves on from. */
    private const OPEN = [
        DpcProposalStatus::Draft,
        DpcProposalStatus::Returned,
        DpcProposalStatus::Submitted,
        DpcProposalStatus::PendingSecretary,
        DpcProposalStatus::Approved,
    ];

    public function __construct(private readonly ProposalAllotmentService $allotments) {}

    public function stageOf(DpcProposal $proposal): ?DpcStage
    {
        return match ($proposal->status) {
            DpcProposalStatus::Draft, DpcProposalStatus::Returned => DpcStage::Ao,
            DpcProposalStatus::Submitted => DpcStage::Ah,
            DpcProposalStatus::PendingSecretary => DpcStage::Secretary,
            DpcProposalStatus::Approved => $this->approvedStage($proposal),
            default => null,
        };
    }

    /**
     * The stage, when it began, its deadline and whether it has passed.
     *
     * @return array{stage: DpcStage, entered_at: Carbon, days: int|null, due_at: Carbon|null, overdue: bool, days_left: int|null}|null
     */
    public function deadline(DpcProposal $proposal, ?Carbon $now = null): ?array
    {
        $stage = $this->stageOf($proposal);

        if ($stage === null) {
            return null;
        }

        $now ??= now();
        $entered = $this->enteredAt($proposal, $stage);
        $days = WorkflowStageTimeline::daysFor($stage);
        $due = $days === null ? null : $entered->copy()->addDays($days);

        return [
            'stage' => $stage,
            'entered_at' => $entered,
            'days' => $days,
            'due_at' => $due,
            'overdue' => $due !== null && $now->greaterThan($due),
            'days_left' => $due === null ? null : (int) floor($now->diffInDays($due, false)),
        ];
    }

    /**
     * The accounts whose move it is at this stage.
     *
     * @return list<int>
     */
    public function actorIds(DpcProposal $proposal, DpcStage $stage): array
    {
        $ids = match ($stage) {
            DpcStage::Ao => array_filter([$this->allotments->current($proposal)?->ao_user_id]),
            DpcStage::Ah => array_filter([$this->allotments->current($proposal)?->ah_user_id]),
            DpcStage::Secretary => $this->roleUserIds(Role::Secretary),
            DpcStage::Chairman => $this->roleUserIds(Role::Chairman),
            DpcStage::Committee => array_merge(
                DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->pluck('allotted_to')->all(),
                DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->whereNotNull('user_id')->pluck('user_id')->all(),
            ),
        };

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Pending With You: the DPCs on which this account is to act now, soonest
     * deadline first.
     *
     * @return list<array{proposal: DpcProposal, deadline: array{stage: DpcStage, entered_at: Carbon, days: int|null, due_at: Carbon|null, overdue: bool, days_left: int|null}}>
     */
    public function pendingWith(User $user): array
    {
        $entries = [];

        foreach ($this->open() as $proposal) {
            $deadline = $this->deadline($proposal);

            if ($deadline !== null && in_array((int) $user->id, $this->actorIds($proposal, $deadline['stage']), true)) {
                $entries[] = ['proposal' => $proposal, 'deadline' => $deadline];
            }
        }

        usort($entries, fn (array $a, array $b): int => ($a['deadline']['due_at']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['deadline']['due_at']?->getTimestamp() ?? PHP_INT_MAX));

        return $entries;
    }

    /**
     * Pending At Me: the DPCs this account has a part in — allotted to it as AO or
     * AH, or on whose committee it sits — that are waiting on somebody else.
     *
     * @return list<array{proposal: DpcProposal, deadline: array{stage: DpcStage, entered_at: Carbon, days: int|null, due_at: Carbon|null, overdue: bool, days_left: int|null}}>
     */
    public function pendingAt(User $user): array
    {
        $entries = [];

        foreach ($this->open() as $proposal) {
            $related = $this->allotments->isAllottedInAnyRole($user, $proposal)
                || DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->where('user_id', $user->id)->exists();
            $deadline = $related ? $this->deadline($proposal) : null;

            if ($deadline !== null && ! in_array((int) $user->id, $this->actorIds($proposal, $deadline['stage']), true)) {
                $entries[] = ['proposal' => $proposal, 'deadline' => $deadline];
            }
        }

        return $entries;
    }

    /**
     * What exactly is pending at this stage (2026-11-17): employee details still
     * incomplete, the sections a committee member returned, documents and PH claims
     * awaiting the AH, the approval, the allotment, the committee's decisions.
     *
     * @return list<string>
     */
    public function pendingItems(DpcProposal $proposal, DpcStage $stage): array
    {
        $details = app(EmployeeDetailsService::class);

        return match ($stage) {
            DpcStage::Ao => $this->aoItems($proposal, $details),
            DpcStage::Ah => array_values(array_filter([
                ($documents = $details->documentsAwaitingHead($proposal)) > 0 ? (string) __('dpc.stage.items.documents', ['count' => $documents]) : null,
                ($claims = $details->phClaimsAwaitingHead($proposal)) > 0 ? (string) __('dpc.stage.items.ph_claims', ['count' => $claims]) : null,
                (string) __('dpc.stage.items.ah_approval'),
            ])),
            DpcStage::Secretary => [(string) __('dpc.stage.items.secretary_approval')],
            DpcStage::Chairman => [(string) __('dpc.stage.items.allotment')],
            DpcStage::Committee => [(string) __('dpc.stage.items.decisions', [
                'done' => DpcCommitteeDecision::query()->where('dpc_proposal_id', $proposal->id)->where('decided_at', '>=', $this->enteredAt($proposal, $stage))->distinct()->count('user_id'),
                'total' => DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()->count(),
            ])],
        };
    }

    /**
     * @return list<string>
     */
    private function aoItems(DpcProposal $proposal, EmployeeDetailsService $details): array
    {
        $returned = $proposal->committeeReturnSections();

        if ($returned !== null) {
            return [(string) __('dpc.stage.items.committee_return', [
                'sections' => implode(', ', array_map(fn (string $section): string => (string) __('dpc.committee_decision.sections.'.$section), $returned)),
            ])];
        }

        $statuses = $details->statuses($proposal);
        $incomplete = $statuses->reject(fn (array $entry): bool => $entry['status']['complete'])->count();

        return array_values(array_filter([
            $proposal->status === DpcProposalStatus::Returned ? (string) __('dpc.stage.items.returned', ['remarks' => (string) $proposal->return_remarks]) : null,
            $incomplete > 0 ? (string) __('dpc.stage.items.employees', ['count' => $incomplete, 'total' => $statuses->count()]) : null,
            (string) __('dpc.stage.items.submit'),
        ]));
    }

    /**
     * Where this account does its work on the DPC at this stage (MoM point 5): the
     * proposal for the department and the Secretary, the allotments for the
     * Chairman, the sitting for the committee.
     */
    public function linkFor(User $user, DpcProposal $proposal, DpcStage $stage): string
    {
        return match (true) {
            $stage === DpcStage::Chairman && $user->hasRole(Role::Chairman->value) => route('cgpsc.allotments.index'),
            $stage === DpcStage::Committee && ! $user->can('view', $proposal) => route('sitting.show', $proposal),
            $user->can('view', $proposal) => route('dpc-proposals.show', $proposal),
            default => route('sitting.show', $proposal),
        };
    }

    /**
     * @return Collection<int, DpcProposal>
     */
    public function open(): Collection
    {
        return DpcProposal::query()
            ->whereIn('status', array_map(fn (DpcProposalStatus $status): string => $status->value, self::OPEN))
            ->with(['department', 'promotionFromPost', 'promotionToPost'])
            ->orderBy('id')
            ->get();
    }

    private function approvedStage(DpcProposal $proposal): ?DpcStage
    {
        $concluded = DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->exists();

        if ($concluded) {
            return null;
        }

        return DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->exists()
            ? DpcStage::Committee
            : DpcStage::Chairman;
    }

    private function enteredAt(DpcProposal $proposal, DpcStage $stage): Carbon
    {
        if ($stage === DpcStage::Committee) {
            $allotted = DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->latest('allotted_at')->value('allotted_at');

            if ($allotted !== null) {
                return Carbon::parse($allotted);
            }
        }

        $entered = WorkflowTransition::query()
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->where('to_state', $proposal->status->value)
            ->latest('occurred_at')
            ->latest('id')
            ->value('occurred_at');

        return Carbon::parse($entered ?? $proposal->created_at ?? now());
    }

    /**
     * @return list<int>
     */
    private function roleUserIds(Role $role): array
    {
        return User::role($role->value)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
