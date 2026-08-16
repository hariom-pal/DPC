<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Enums\Role;

/**
 * Who may raise, approve and read a circulation note — M9 Phase 1.
 *
 * **Department-scoped, unlike M6's and M7's policies.** A circulation begins
 * inside the department that holds the DPC, so every department-side ability
 * checks `department_id`. The Commission's abilities — consent and sign —
 * arrive with Phase 2 and will not carry a department check, for the same
 * reason M7's do not: CGPSC users hold no department.
 *
 * Every rule here is asserted **again in the service**, so a non-HTTP caller —
 * a console command, a queued job, a future API — is bound by the same rules
 * as a browser.
 */
class DpcCirculationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole([
            Role::AuthorizedOfficer->value,
            Role::AdministrativeHead->value,
            Role::Chairman->value,
            Role::Member->value,
        ]);
    }

    public function view(User $user, DpcCirculation $circulation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // The Commission reads any circulation; it holds no department.
        if ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])) {
            return true;
        }

        return $user->department_id === $circulation->department_id
            && $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    /**
     * Raising one. The origin's admissibility — concluded, holds in force, not
     * superseded — is the service's job, because at this point no circulation
     * exists to authorise against.
     */
    public function create(User $user): bool
    {
        return $user->is_active
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id !== null;
    }

    public function submit(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id === $circulation->department_id
            && $circulation->isEditable();
    }

    public function cancel(User $user, DpcCirculation $circulation): bool
    {
        return $this->submit($user, $circulation);
    }

    /**
     * Approving and signing (AQ-23). The Administrative Head, in the owning
     * department, on a submitted note.
     */
    public function approve(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $user->hasRole(Role::AdministrativeHead->value)
            && $user->department_id === $circulation->department_id
            && $circulation->status->value === 'submitted';
    }

    public function return(User $user, DpcCirculation $circulation): bool
    {
        return $this->approve($user, $circulation);
    }
}
