<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\Role;

/**
 * Ownership-based authorization for DpcProposal (architect ruling,
 * 2026-08-13: Policies from M4 onward, replacing the inline
 * `abort_unless($department_id === ...)` pattern used through M3).
 */
class DpcProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    public function view(User $user, DpcProposal $proposal): bool
    {
        return $this->sameDepartment($user, $proposal);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::AuthorizedOfficer->value) && $user->department_id !== null;
    }

    public function update(User $user, DpcProposal $proposal): bool
    {
        return $user->hasRole(Role::AuthorizedOfficer->value)
            && $this->sameDepartment($user, $proposal)
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
        return $user->hasRole(Role::AdministrativeHead->value)
            && $this->sameDepartment($user, $proposal)
            && $proposal->status === DpcProposalStatus::Submitted;
    }

    public function return(User $user, DpcProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }

    private function sameDepartment(User $user, DpcProposal $proposal): bool
    {
        return $user->department_id !== null && $user->department_id === $proposal->department_id;
    }
}
