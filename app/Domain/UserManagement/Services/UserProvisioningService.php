<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\MobileVerificationWaiver;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\IssuedCredentials;
use App\Enums\Role;
use App\Http\Middleware\EnsureAccountReady;
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
        private readonly MobileVerificationWaiver $waiver,
    ) {}

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null,date_of_birth?:string|null,gender?:string|null}  $data
     */
    public function provision(array $data, ?User $actor = null): IssuedCredentials
    {
        return DB::transaction(function () use ($data, $actor): IssuedCredentials {
            $password = $this->temporaryPassword();

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'designation_id' => $data['designation_id'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $user->password = Hash::make($password);
            $user->save();

            $this->grant($user, Role::from($data['role']), $data['department_id'] ?? null, $actor);

            // The mobile cannot be proved by OTP while the SMS gateway is down: the
            // exception is recorded against the administrator who created the account.
            $this->waiver->grant($user, $actor);

            CredentialsIssued::dispatch($user, CredentialsIssued::REASON_CREATED);
            $this->timeline->record('user.provisioned', $user, "Account provisioned with role {$data['role']}", causer: $actor);

            return new IssuedCredentials($user, $password);
        });
    }

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role?:string,department_id?:int|null}  $data  `role` absent: the role is not changed
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $emailChanged = mb_strtolower((string) $user->email) !== mb_strtolower((string) $data['email']);
            $mobileChanged = (string) $user->mobile !== (string) $data['mobile'];

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'designation_id' => $data['designation_id'] ?? null,
            ]);

            // A changed address has not been proved: its verification goes with it
            // (2026-10-26), so a status never claims more than an OTP showed.
            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            if ($mobileChanged) {
                $user->mobile_verified_at = null;
                $user->mobile_verification_waived_at = null;
                $user->mobile_verification_waived_by = null;
                $user->mobile_verification_waiver_reason = null;
            }

            $user->save();

            if ($mobileChanged) {
                $this->waiver->grant($user, $actor);
            }

            if (isset($data['role'])) {
                $this->grant($user, Role::from($data['role']), $data['department_id'] ?? null, $actor);
            }

            $this->timeline->record('user.updated', $user, 'Account details updated', causer: $actor);

            return $user;
        });
    }

    private function grant(User $user, Role $role, ?int $departmentId, ?User $actor = null): void
    {
        if ($role->isDepartmental()) {
            // Guarded rather than assumed: the Form Request requires a department
            // for these roles, but this service is also called from seeders and
            // from the console, and a silent null here would create exactly the
            // role-without-department account described above.
            $department = Department::query()->findOrFail($departmentId);

            $this->assignments->assign($user, $department, $role, remarks: $actor?->hasRole(Role::DepartmentAdmin->value) ? 'Set by the Department Admin.' : 'Set by the CGPSC Administrator.');

            return;
        }

        if ($user->activeAssignment !== null) {
            $this->assignments->release($user, remarks: 'Released on being given a Commission role.');
        }

        $user->syncRoles([$role->value]);
    }

    /**
     * Activate or deactivate an account.
     *
     * Deactivation ends the account's sessions (Phase 0, 2026-10-27): the
     * remember-me token is rotated, the account gate ({@see EnsureAccountReady})
     * refuses its sessions at their next request wherever they are, and with the
     * database session driver its stored sessions are deleted outright.
     */
    public function setActive(User $user, bool $active, ?User $actor = null): void
    {
        $user->forceFill(['is_active' => $active])->save();

        if (! $active) {
            app(AccountSessions::class)->invalidate($user, 'deactivated', $actor);
        }

        $this->timeline->record($active ? 'user.activated' : 'user.deactivated', $user, causer: $actor);
        app(SecurityLog::class)->record($active ? 'account.activated' : 'account.deactivated', $user, actor: $actor);
    }

    /**
     * Replace the account's password with a NEW temporary one and return it, once,
     * for the issuing administrator's screen (business decision, 2026-10-26).
     *
     * The old password stops working at once, the remember-me token is rotated so a
     * "remember me" cookie no longer signs in, and every other session ends at its
     * next request (`AuthenticateSession` compares the stored password hash). The
     * password is never sent, logged or audited; the officer is told only that a
     * reset happened.
     */
    public function resetPassword(User $user, ?User $actor = null): string
    {
        $password = $this->temporaryPassword();

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'password_changed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        CredentialsIssued::dispatch($user, CredentialsIssued::REASON_RESET);
        $this->timeline->record('user.password_reset', $user, 'Temporary password re-issued', causer: $actor);

        return $password;
    }

    private function temporaryPassword(): string
    {
        return Str::password(max(12, (int) config('dpc.accounts.password_length', 16)));
    }
}
