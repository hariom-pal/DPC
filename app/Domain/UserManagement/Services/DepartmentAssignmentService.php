<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a user is posted to a department, transferred, or released
 * (change item 2).
 *
 * **Three things have to happen together or none of them may happen.** The
 * incumbent assignment is retired, the new one is activated, and the Spatie role
 * grant is re-projected from the new one. Done separately, the failure modes are
 * each worse than the last: two active assignments (which the unique index would
 * reject, aborting a half-finished transfer), or a role that outlives the posting
 * that granted it — an officer who still holds Authorized Officer in a department
 * they left, and can still file its proposals.
 *
 * So every method here is one transaction, and the incumbent is read
 * `lockForUpdate()`. That is the same doctrine as TD-5: take the write lock
 * before deciding, because two administrators transferring the same officer at
 * the same moment is exactly how a "check then write" produces a state the
 * database was supposed to make impossible.
 *
 * **Nothing here creates or deletes a user.** The register is explicit —
 * "Never create duplicate users" — so a transfer takes a `User` that already
 * exists and gives it a new posting. The account, its login, its signatures and
 * its notification history are untouched.
 */
class DepartmentAssignmentService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * Post a user to a department in a role, retiring whatever posting they held.
     *
     * This is the transfer operation and the first-posting operation at once,
     * because they are the same operation: the register describes a transfer as
     * "deactivate previous assignment, create new assignment", and a first
     * posting is that with nothing to deactivate. Having one method means a first
     * posting cannot take a path that a transfer does not, which is where the
     * "role left behind" bug would otherwise live.
     *
     * `$effectiveFrom` defaults to today rather than being required, because the
     * common case is a transfer taking effect now; a back-dated or future-dated
     * posting is supported and is the administrator's explicit choice.
     */
    public function assign(
        User $user,
        Department $department,
        Role $role,
        ?Carbon $effectiveFrom = null,
        ?string $remarks = null,
    ): UserDepartmentAssignment {
        return DB::transaction(function () use ($user, $department, $role, $effectiveFrom, $remarks): UserDepartmentAssignment {
            $from = ($effectiveFrom ?? Carbon::today())->startOfDay();

            $previous = $this->retireIncumbent($user, $from);

            $assignment = new UserDepartmentAssignment([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'role' => $role->value,
                'effective_from' => $from,
                'active_flag' => 1,
                'remarks' => $remarks,
            ]);
            $assignment->save();

            $this->projectRole($user, $role);

            $this->timeline->record(
                $previous === null ? 'user.assigned' : 'user.transferred',
                $user,
                $previous === null
                    ? sprintf('Posted to %s as %s with effect from %s.', $department->name, $role->label(), $from->toDateString())
                    : sprintf(
                        'Transferred from %s to %s as %s with effect from %s.',
                        $previous->department === null ? 'a previous department' : $previous->department->name,
                        $department->name,
                        $role->label(),
                        $from->toDateString(),
                    ),
            );

            // The caller almost always goes on to read `$user->department`, and
            // the accessor reads a relation that was loaded before this ran.
            $user->unsetRelation('activeAssignment');

            return $assignment;
        });
    }

    /**
     * Release a user from their current posting without giving them another.
     *
     * Leaves the account intact and role-less: an officer between postings can
     * still log in, see their notifications and change their password, and can do
     * nothing that requires a department. That is deliberately different from
     * deactivating the account, which is `UserProvisioningService::setActive()`
     * and means something else entirely.
     */
    public function release(User $user, ?Carbon $on = null, ?string $remarks = null): ?UserDepartmentAssignment
    {
        return DB::transaction(function () use ($user, $on, $remarks): ?UserDepartmentAssignment {
            $retired = $this->retireIncumbent($user, ($on ?? Carbon::today())->startOfDay(), $remarks);

            if ($retired === null) {
                return null;
            }

            // Every role this user holds *because of* a posting goes with the
            // posting. A Commission role is not removed, because no posting
            // granted it — that would be this method silently demoting an
            // administrator who also once held a departmental role.
            $user->removeRole($retired->role->value);
            $user->unsetRelation('activeAssignment');

            $this->timeline->record('user.released', $user, sprintf(
                'Released from %s with effect from %s.',
                $retired->department === null ? 'their department' : $retired->department->name,
                $retired->effective_to?->toDateString() ?? Carbon::today()->toDateString(),
            ));

            return $retired;
        });
    }

    /**
     * Retire the user's active assignment, if they have one.
     *
     * Read under a write lock: the unique index makes two active assignments
     * impossible, so without the lock a concurrent transfer would not corrupt
     * anything — it would simply fail, mid-transfer, with a constraint violation
     * the administrator cannot act on. The lock turns that into a wait.
     */
    private function retireIncumbent(User $user, Carbon $on, ?string $remarks = null): ?UserDepartmentAssignment
    {
        $incumbent = UserDepartmentAssignment::query()
            ->with('department')
            ->where('user_id', $user->id)
            ->whereNotNull('active_flag')
            ->lockForUpdate()
            ->first();

        if ($incumbent === null) {
            return null;
        }

        // A posting that ends the day the next one begins would otherwise read as
        // having covered that day twice.
        $incumbent->deactivate($on->copy()->subDay()->max($incumbent->effective_from));

        if ($remarks !== null) {
            $incumbent->remarks = $remarks;
        }

        $incumbent->save();

        return $incumbent;
    }

    /**
     * Make the Spatie grant say what the assignment says.
     *
     * `syncRoles` on the departmental roles only, rather than on everything: an
     * account may legitimately hold a Commission role as well (the Administrator
     * who is also registered as a Member), and replacing the whole grant set
     * would strip it. So the departmental grants are cleared and the assignment's
     * role is added — which is the projection this service exists to keep honest.
     */
    private function projectRole(User $user, Role $role): void
    {
        foreach (Role::departmental() as $departmental) {
            if ($departmental !== $role && $user->hasRole($departmental->value)) {
                $user->removeRole($departmental->value);
            }
        }

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }
    }
}
