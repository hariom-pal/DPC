<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The CGPSC Administrator's provisioning of accounts.
 *
 * Provisioned accounts are created inactive-of-verification: the holder logs in
 * with the issued temporary password, completes email + mobile OTP verification
 * (reusing the M1 flow), then is forced to change the password. Credentials are
 * delivered via the queued CredentialsIssued pipeline.
 *
 * **Since 2026-08-24 this covers department accounts too** (change item 1). The
 * register put Users *and* Role Assignments under the single CGPSC Administrator,
 * so the Administrator can post an Authorized Officer or Administrative Head
 * directly rather than only through S6 department registration. Which route a
 * role takes is {@see grant()}'s decision and nowhere else's.
 */
class UserProvisioningService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly DepartmentAssignmentService $assignments,
    ) {}

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null}  $data
     */
    public function provision(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $password = Str::password(12);

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'designation_id' => $data['designation_id'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $user->password = Hash::make($password);
            $user->save();

            $this->grant($user, Role::from($data['role']), $data['department_id'] ?? null);

            CredentialsIssued::dispatch($user, $password);
            $this->timeline->record('user.provisioned', $user, "Account provisioned with role {$data['role']}");

            return $user;
        });
    }

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'designation_id' => $data['designation_id'] ?? null,
            ])->save();

            $this->grant($user, Role::from($data['role']), $data['department_id'] ?? null);

            $this->timeline->record('user.updated', $user, 'Account details updated');

            return $user;
        });
    }

    /**
     * Give the account the role it should hold, by whichever of the two routes
     * that role uses.
     *
     * **A departmental role is never granted directly.** It is granted by posting
     * the officer to a department, which is what `DepartmentAssignmentService`
     * does and what makes the grant dated, reversible and answerable later
     * (change item 2). Calling `syncRoles()` for an Authorized Officer would
     * produce an officer who holds the role and belongs to no department — able
     * to reach every departmental screen and see nothing on any of them.
     *
     * **Changing a departmental role or department is a transfer**, and goes
     * through the same call: `assign()` retires the incumbent posting and opens a
     * new one, so "moved from Higher Education to Forest" and "promoted from AO to
     * AH within Higher Education" are both recorded as what they are rather than
     * as an edit that overwrote the previous answer.
     *
     * Moving an account *out* of a departmental role and into a Commission one
     * releases the posting first — otherwise the officer would remain the
     * department's AO in the assignment table while holding Chairman in the role
     * table.
     */
    private function grant(User $user, Role $role, ?int $departmentId): void
    {
        if ($role->isDepartmental()) {
            // Guarded rather than assumed: the Form Request requires a department
            // for these roles, but this service is also called from seeders and
            // from the console, and a silent null here would create exactly the
            // role-without-department account described above.
            $department = Department::query()->findOrFail($departmentId);

            $this->assignments->assign($user, $department, $role, remarks: 'Set by the CGPSC Administrator.');

            return;
        }

        if ($user->activeAssignment !== null) {
            $this->assignments->release($user, remarks: 'Released on being given a Commission role.');
        }

        $user->syncRoles([$role->value]);
    }

    public function setActive(User $user, bool $active): void
    {
        $user->forceFill(['is_active' => $active])->save();
        $this->timeline->record($active ? 'user.activated' : 'user.deactivated', $user);
    }

    /**
     * Re-issue a temporary password, and **return it**.
     *
     * The return value is the fix for a dead end reported on 2026-09-07: *"I reset
     * the password and it should let me log in with Demo@2026, but it does not."*
     * It never was `Demo@2026` — this issues a random twelve characters — and the
     * only copy went out over SMS and e-mail, which in a local or a mock-gateway
     * deployment means it went to a log file. The Administrator was told *"a new
     * temporary password has been issued"* and given no way to learn what it was, so
     * pressing the button locked the account out rather than recovering it.
     *
     * The password is still delivered to the user by the usual channel. This is the
     * second copy, for the Administrator standing at the screen who has to hand it
     * over — the same treatment the Seniority List already gives the candidate
     * accounts it creates, and for the same reason.
     */
    public function resetPassword(User $user): string
    {
        $password = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        CredentialsIssued::dispatch($user, $password);
        $this->timeline->record('user.password_reset', $user, 'Temporary password re-issued');

        return $password;
    }
}
