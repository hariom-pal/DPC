<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Enums\Role;

/**
 * What comes next on a DPC, for the officer looking at it (MoM of 12 November 2026,
 * point 1): the sentence the pop-up after each step shows, and the element the green
 * "next step" flag marks — or null for the target where the next step is someone
 * else's.
 */
class DpcNextStep
{
    public function __construct(
        private readonly ProposalAllotmentService $allotments,
        private readonly DpcProposalCompletenessService $completeness,
    ) {}

    /**
     * @return array{text: string, target: string|null}|null
     */
    public function for(DpcProposal $proposal, ?User $user): ?array
    {
        $isAo = $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer);
        $isAh = $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead);

        if (! $isAo && ! $isAh) {
            return null;
        }

        if ($proposal->status === DpcProposalStatus::Cancelled || $proposal->proceeding_completed_at !== null) {
            return null;
        }

        $step = fn (string $key, ?string $target = null, array $params = []): array => ['text' => (string) __('dpc.next_step.'.$key, $params), 'target' => $target];

        if ($proposal->status->isEditable()) {
            if (! $isAo) {
                return $step('with_ao');
            }

            $pending = collect($this->completeness->checklist($proposal))->first(fn (array $section): bool => ! $section['complete']);

            return $pending === null
                ? $step('submit', 'dpc-submit')
                : $step('complete_section', 'checklist-'.$pending['key'], ['section' => $pending['label']]);
        }

        if ($proposal->status === DpcProposalStatus::Submitted) {
            return $isAh ? $step('approve', 'dpc-approve') : $step('with_ah');
        }

        $proceeding = DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->latest('id')->first();

        if ($proceeding?->closed_at === null) {
            return $step('with_cgpsc');
        }

        $ordered = DpcPromotionOrder::query()->where('dpc_proposal_id', $proposal->id)->where('status', DpcPromotionOrder::STATUS_ISSUED)->exists();

        if (! $ordered) {
            return $isAh ? $step('issue_order', 'section-promotion-order') : $step('with_ah_order');
        }

        return $isAo ? $step('mark_complete', 'dpc-complete') : $step('with_ao_complete');
    }

    /**
     * The same for a Pre-DPC.
     *
     * @return array{text: string, target: string|null}|null
     */
    public function forPreDpc(PreDpcProposal $preDpc, ?User $user): ?array
    {
        $isAo = $this->allotments->isAllotted($user, $preDpc, Role::AuthorizedOfficer);
        $isAh = $this->allotments->isAllotted($user, $preDpc, Role::AdministrativeHead);
        $step = fn (string $key, ?string $target = null): array => ['text' => (string) __('dpc.next_step.pre_dpc.'.$key), 'target' => $target];

        if ((! $isAo && ! $isAh) || $preDpc->status === PreDpcStatus::Cancelled) {
            return null;
        }

        return match ($preDpc->status) {
            PreDpcStatus::Draft, PreDpcStatus::Returned => $isAo ? $step('submit', 'pre-dpc-declaration') : $step('with_ao'),
            PreDpcStatus::Submitted => $isAh ? $step('approve', 'pre-dpc-approve') : $step('with_ah'),
            default => DpcProposal::query()->where('pre_dpc_proposal_id', $preDpc->id)->exists()
                ? null
                : ($isAo ? $step('create_dpc') : $step('with_ao_dpc')),
        };
    }
}
