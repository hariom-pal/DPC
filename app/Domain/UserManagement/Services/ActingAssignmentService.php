<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The department an officer is acting in (Phase 6, 2026-10-27).
 *
 * An officer may hold several current assignments — a primary posting and an
 * additional charge, possibly in different roles. Each request acts in exactly
 * one of them: the one the officer chose (by id, in the session), re-checked
 * against the database on every request — still active, within its dates, the
 * officer's own — or by default the primary posting. Its department and role are
 * then the request's: `$user->department_id`, `$user->department` and the acting
 * role all read it, so a policy that compares departments compares the acting
 * one, and a Spatie role granted for another department never reaches here.
 */
class ActingAssignmentService
{
    public const SESSION_KEY = 'acting_assignment_id';

    public function __construct(private readonly Session $session) {}

    /**
     * The officer's current assignments: active and within their dates today.
     *
     * @return Collection<int, UserDepartmentAssignment>
     */
    public function current(User $user): Collection
    {
        return UserDepartmentAssignment::query()
            ->current()
            ->with('department')
            ->where('user_id', $user->id)
            ->orderByRaw('primary_flag IS NULL')
            ->orderBy('id')
            ->get();
    }

    /**
     * The assignment this request acts in for a departmental role, or null when
     * the officer holds no current assignment in that role.
     */
    public function resolve(User $user, Role $role): ?UserDepartmentAssignment
    {
        $candidates = $this->current($user)->filter(fn (UserDepartmentAssignment $a): bool => $a->role === $role);
        $chosen = (int) $this->session->get(self::SESSION_KEY);

        return $candidates->firstWhere('id', $chosen) ?? $candidates->first();
    }

    public function switchTo(User $user, UserDepartmentAssignment $assignment): bool
    {
        if ($this->current($user)->doesntContain('id', $assignment->id)) {
            return false;
        }

        $this->session->put(self::SESSION_KEY, $assignment->id);

        return true;
    }
}
