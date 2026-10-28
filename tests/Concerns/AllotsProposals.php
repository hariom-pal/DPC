<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;

/**
 * Allot a fixture proposal to its AO and AH (2026-10-26), as the Department Admin
 * would. A proposal nobody is allotted is refused to every officer (A4), so a
 * fixture that raises one and then acts on it as an AO or AH allots it first.
 *
 * The officers default to the department's first active AO and AH; an AH is
 * created only when the department has none, because the allotment names both.
 */
trait AllotsProposals
{
    protected function allot(PreDpcProposal|DpcProposal $proposal, ?User $ao = null, ?User $ah = null): ProposalAllotment
    {
        $service = app(ProposalAllotmentService::class);
        $ao ??= $this->firstOfficer($proposal->department_id, Role::AuthorizedOfficer);
        $ah ??= $this->firstOfficer($proposal->department_id, Role::AdministrativeHead);
        $current = $service->current($proposal);

        if ($current !== null && $current->ao_user_id === $ao->id && $current->ah_user_id === $ah->id) {
            return $current;
        }

        return $service->allot($proposal, $ao, $ah, null, $current === null ? null : 'Fixture reassignment');
    }

    protected function departmentHasOfficer(int $departmentId, Role $role): bool
    {
        return UserDepartmentAssignment::query()->active()
            ->where('department_id', $departmentId)
            ->where('role', $role->value)
            ->exists();
    }

    private function firstOfficer(int $departmentId, Role $role): User
    {
        $id = UserDepartmentAssignment::query()->active()
            ->where('department_id', $departmentId)
            ->where('role', $role->value)
            ->whereHas('user', fn ($user) => $user->where('is_active', true))
            ->orderBy('id')
            ->value('user_id');

        return $id !== null
            ? User::query()->findOrFail($id)
            : User::factory()->assignedTo($departmentId, $role)->create();
    }
}
