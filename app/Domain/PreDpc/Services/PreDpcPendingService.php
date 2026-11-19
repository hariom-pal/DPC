<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Services;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;

/**
 * The Pre-DPC and its Vacancy Management record as one task in Pending With You
 * (2026-11-19): the AO's to prepare and submit together, the AH's to approve or
 * return together.
 */
final class PreDpcPendingService
{
    public function __construct(
        private readonly ProposalAllotmentService $allotments,
        private readonly VacancyMasterService $vacancies,
    ) {}

    /**
     * @return list<array{proposal: PreDpcProposal, vacancy: VacancyPosition|null, task: string}>
     */
    public function pendingWith(User $user): array
    {
        $ids = ProposalAllotment::query()->current()->whereNull('held_at')->whereNotNull('pre_dpc_proposal_id')
            ->where(fn ($query) => $query->where('ao_user_id', $user->id)->orWhere('ah_user_id', $user->id))
            ->pluck('pre_dpc_proposal_id');

        $entries = [];

        $proposals = PreDpcProposal::query()->whereIn('id', $ids)
            ->whereIn('status', [PreDpcStatus::Draft->value, PreDpcStatus::Returned->value, PreDpcStatus::Submitted->value])
            ->with('promotionToPost')->orderBy('id')->get();

        foreach ($proposals as $proposal) {
            $role = $proposal->status === PreDpcStatus::Submitted ? Role::AdministrativeHead : Role::AuthorizedOfficer;

            if (! $this->allotments->isAllotted($user, $proposal, $role)) {
                continue;
            }

            $vacancy = $this->vacancies->recordForPreDpc($proposal);
            $task = match ($proposal->status) {
                PreDpcStatus::Submitted => __('predpc.pending.ah_decide'),
                PreDpcStatus::Returned => __('predpc.pending.ao_returned', ['remarks' => (string) $proposal->return_remarks]),
                default => __('predpc.pending.ao_prepare'),
            };

            $entries[] = ['proposal' => $proposal, 'vacancy' => $vacancy, 'task' => (string) $task];
        }

        return $entries;
    }
}
