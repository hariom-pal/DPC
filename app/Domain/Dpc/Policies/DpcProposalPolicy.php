<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Policies;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;

/**
 * Ownership-based authorization for DpcProposal (architect ruling,
 * 2026-08-13: Policies from M4 onward, replacing the inline
 * `abort_unless($department_id === ...)` pattern used through M3).
 *
 * **Allotment (2026-10-26, docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).** A DPC is the
 * work of the one AO and one AH it is allotted to — inherited from its Pre-DPC and
 * reassignable by the Department Admin — not of every AO and AH of the
 * department. An officer who is not allotted does not see it (A3); a proposal with
 * no allotment waits for the Department Admin (A4).
 */
class DpcProposalPolicy
{
    public function __construct(private readonly ProposalAllotmentService $allotments) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    public function view(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllottedInAnyRole($user, $proposal);
    }

    /**
     * An AO may raise DPCs; *which* Pre-DPC it may raise one from is
     * {@see raiseFrom()}.
     */
    public function create(User $user): bool
    {
        // Raising is from a Pre-DPC this officer is allotted to (raiseFrom).
        return $user->hasRole(Role::AuthorizedOfficer->value);
    }

    /**
     * A DPC is raised by the AO the Pre-DPC is allotted to (A2).
     */
    public function raiseFrom(User $user, PreDpcProposal $preDpc): bool
    {
        return $this->allotments->isAllotted($user, $preDpc, Role::AuthorizedOfficer);
    }

    public function update(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status->isEditable();
    }

    public function submit(User $user, DpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    public function cancel(User $user, DpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    public function approve(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead)
            && $proposal->status === DpcProposalStatus::Submitted;
    }

    public function return(User $user, DpcProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }

    /**
     * Issuing the promotion order after the Commission's decision: the allotted AH
     * (replaces a raw department-and-role check, 2026-10-26).
     */
    public function issueOrder(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead);
    }
}
