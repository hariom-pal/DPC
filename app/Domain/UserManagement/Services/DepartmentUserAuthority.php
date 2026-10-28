<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Illuminate\Support\Collection;

/**
 * What a Department Admin may manage (business decision, 2026-10-26).
 *
 * The Authorized Officers and Administrative Heads posted to the Department Admin's
 * own department — and nobody else: not another department's officers, not another
 * Department Admin, not a Commission or administrative account, and not an officer
 * who also holds any role beyond AO or AH. Checked on every action, including the
 * password reset, so a guessed URL for another department's user is refused.
 */
class DepartmentUserAuthority
{
    public function isDepartmentAdmin(?User $actor): bool
    {
        // The role alone is not enough (2026-10-27): administering one DPC also
        // projects it, and must open nothing department-wide. Only a current
        // Department Admin posting in the account's department does.
        return $actor !== null
            && $actor->is_active
            && $actor->hasRole(Role::DepartmentAdmin->value)
            && $actor->department_id !== null
            && UserDepartmentAssignment::query()->current()
                ->where('user_id', $actor->id)
                ->where('department_id', $actor->department_id)
                ->where('role', Role::DepartmentAdmin->value)
                ->exists();
    }

    public function canManage(?User $actor, User $target): bool
    {
        if (! $this->isDepartmentAdmin($actor) || $actor === null || $actor->is($target)) {
            return false;
        }

        $posting = $target->activeAssignment()->first();

        if ($posting === null || $posting->department_id !== $actor->department_id) {
            return false;
        }

        if (! in_array($posting->role, Role::departmentManageable(), true)) {
            return false;
        }

        return array_diff($target->grantedRoleNames(), Role::departmentManageableValues()) === [];
    }

    /**
     * The officers the Department Admin manages, newest first.
     *
     * @return Collection<int, User>
     */
    public function managedUsers(User $actor): Collection
    {
        if (! $this->isDepartmentAdmin($actor)) {
            return new Collection;
        }

        return UserDepartmentAssignment::query()
            ->active()
            ->with(['user.roles', 'user.latestIdentityVerification'])
            ->where('department_id', $actor->department_id)
            ->whereIn('role', Role::departmentManageableValues())
            ->get()
            ->map(fn (UserDepartmentAssignment $posting): ?User => $posting->user)
            ->filter(fn (?User $user): bool => $user !== null && $this->canManage($actor, $user))
            ->sortBy('name')
            ->values();
    }
}
