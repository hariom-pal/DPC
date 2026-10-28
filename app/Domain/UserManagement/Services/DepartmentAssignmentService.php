<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
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

            // An additional charge in the department the officer is now posted to
            // ends with the posting: one assignment per officer per department.
            $this->endIn($user, $department, $from, 'Superseded by a primary posting to the same department.');

            $assignment = new UserDepartmentAssignment([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'role' => $role->value,
                'kind' => UserDepartmentAssignment::KIND_PRIMARY,
                'effective_from' => $from,
                'active_flag' => 1,
                'primary_flag' => 1,
                'remarks' => $remarks,
            ]);
            $assignment->save();

            $this->projectRoles($user);

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
            // posting — unless another current assignment still grants it. A
            // Commission role is not removed, because no posting granted it.
            $this->projectRoles($user);
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
            ->whereNotNull('primary_flag')
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
    /**
     * An additional charge (Phase 8, 2026-10-27): the officer keeps the primary
     * posting and is assigned in a second department, in either departmental
     * role, approved by the CGPSC Super Admin, optionally until an end date.
     * Never a second assignment in a department the officer already holds one in
     * — which is also what keeps one officer from being AO and AH of one department.
     *
     * @throws AssignmentRefused
     */
    public function addAdditionalCharge(
        User $user,
        Department $department,
        Role $role,
        Carbon $effectiveFrom,
        ?Carbon $effectiveTo,
        ?User $approvedBy,
        ?string $remarks = null,
    ): UserDepartmentAssignment {
        if (! in_array($role, Role::departmentManageable(), true)) {
            throw new AssignmentRefused((string) __('assignments.errors.role'));
        }

        return DB::transaction(function () use ($user, $department, $role, $effectiveFrom, $effectiveTo, $approvedBy, $remarks): UserDepartmentAssignment {
            $held = UserDepartmentAssignment::query()
                ->where('user_id', $user->id)
                ->where('department_id', $department->id)
                ->whereNotNull('active_flag')
                ->lockForUpdate()
                ->exists();

            if ($held) {
                throw new AssignmentRefused((string) __('assignments.errors.already_in_department'));
            }

            $assignment = UserDepartmentAssignment::query()->create([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'role' => $role->value,
                'kind' => UserDepartmentAssignment::KIND_ADDITIONAL_CHARGE,
                'effective_from' => $effectiveFrom->copy()->startOfDay(),
                'effective_to' => $effectiveTo?->copy()->startOfDay(),
                'active_flag' => 1,
                'approved_by' => $approvedBy?->id,
                'approved_at' => $approvedBy === null ? null : now(),
                'remarks' => $remarks,
            ]);

            $this->projectRoles($user);
            $this->timeline->record('user.additional_charge_started', $user, sprintf(
                'Additional charge of %s as %s from %s%s.',
                $department->name,
                $role->label(),
                $effectiveFrom->toDateString(),
                $effectiveTo === null ? '' : ' until '.$effectiveTo->toDateString(),
            ), causer: $approvedBy);

            return $assignment;
        });
    }

    /**
     * End one assignment (an additional charge removed or expired).
     */
    public function end(UserDepartmentAssignment $assignment, Carbon $on, ?string $remarks = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($assignment, $on, $remarks, $actor): void {
            $assignment = UserDepartmentAssignment::query()->with(['user', 'department'])->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->active_flag === null) {
                return;
            }

            $assignment->deactivate($on->copy()->startOfDay()->max($assignment->effective_from));
            if ($remarks !== null) {
                $assignment->remarks = $remarks;
            }
            $assignment->save();

            $this->projectRoles($assignment->user);
            $this->timeline->record('user.assignment_ended', $assignment->user, sprintf(
                '%s assignment in %s ended%s.',
                $assignment->isAdditionalCharge() ? 'Additional-charge' : 'Primary',
                $assignment->department->name,
                $remarks === null ? '' : ': '.$remarks,
            ), causer: $actor);
        });
    }

    private function endIn(User $user, Department $department, Carbon $on, string $remarks): void
    {
        UserDepartmentAssignment::query()
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->whereNotNull('active_flag')
            ->lockForUpdate()
            ->get()
            ->each(function (UserDepartmentAssignment $held) use ($on, $remarks): void {
                $held->deactivate($on->copy()->subDay()->max($held->effective_from));
                $held->remarks = $remarks;
                $held->save();
            });
    }

    /**
     * The departmental Spatie roles are a projection of the officer's active
     * assignments: exactly the roles some active assignment grants (Phase 6). The
     * department a role applies to is never read from Spatie — only from the
     * acting assignment (ActingAssignmentService).
     */
    public function projectRoles(User $user): void
    {
        $granted = UserDepartmentAssignment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('active_flag')
            ->pluck('role')
            ->map(fn ($role): string => $role instanceof Role ? $role->value : (string) $role)
            ->unique()
            ->all();

        // A DPC allotment grants the role too (2026-10-27): the AO or AH of a
        // current allotment that is not on hold. What the role reaches is still
        // decided per DPC (ProposalAllotmentService::isAllotted).
        $allotments = ProposalAllotment::query()->current()->whereNull('held_at');

        if ((clone $allotments)->where('ao_user_id', $user->id)->exists()) {
            $granted[] = Role::AuthorizedOfficer->value;
        }

        if ((clone $allotments)->where('ah_user_id', $user->id)->exists()) {
            $granted[] = Role::AdministrativeHead->value;
        }

        // Read from the database, not the `roles` relation: during a request that
        // relation is narrowed to the acting role (ResolveActiveRole).
        $user->unsetRelation('roles');
        $held = $user->grantedRoleNames();

        foreach (Role::departmental() as $departmental) {
            $should = in_array($departmental->value, $granted, true);
            $has = in_array($departmental->value, $held, true);

            if ($should && ! $has) {
                $user->assignRole($departmental->value);
            } elseif (! $should && $has) {
                $user->removeRole($departmental->value);
            }
        }

        $user->unsetRelation('roles');

        $user->unsetRelation('activeAssignment');
    }
}
