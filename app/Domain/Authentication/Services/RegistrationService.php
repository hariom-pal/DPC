<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Department registration (PPT S5–S6).
 *
 * Registers a department and provisions its two-tier accounts — Authorized
 * Officer and Administrative Head — then issues login credentials (queued, via
 * the CredentialsIssued event). Each officer has their own official email and
 * mobile; the department contact email is stored separately on the department.
 * Officers log in with email or mobile and must change the system-issued
 * password on first login.
 *
 * The two tiers used to be recorded twice — as `users.level` (1 and 2) and as a
 * role — and `level` was dropped on 2026-08-24 because two columns asserting one
 * fact is how they come to disagree. The role, held on the officer's assignment,
 * is now the only statement of which tier this is.
 */
class RegistrationService
{
    public function __construct(private readonly DepartmentAssignmentService $assignments) {}

    /**
     * Register the department, provision AO + AH accounts, issue credentials.
     * Returns the Authorized Officer (registrant) so the caller can start
     * OTP verification.
     *
     * @param  array{department_id:int,district_id:int,place:string,pin:string,department_email:string,ao_name:string,ao_designation_id:int,ao_mobile:string,ao_email:string,ah_name:string,ah_designation_id:int,ah_mobile:string,ah_email:string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $department = Department::query()->findOrFail($data['department_id']);

            $department->forceFill([
                'district_id' => $data['district_id'],
                'place' => $data['place'],
                'pin' => $data['pin'],
                'email' => $data['department_email'],
                'status' => DepartmentStatus::Registered->value,
                'registered_at' => now(),
            ])->save();

            $ao = $this->createOfficer($department, [
                'name' => $data['ao_name'],
                'designation_id' => $data['ao_designation_id'],
                'mobile' => $data['ao_mobile'],
                'email' => $data['ao_email'],
            ], Role::AuthorizedOfficer);

            $this->createOfficer($department, [
                'name' => $data['ah_name'],
                'designation_id' => $data['ah_designation_id'],
                'mobile' => $data['ah_mobile'],
                'email' => $data['ah_email'],
            ], Role::AdministrativeHead);

            return $ao;
        });
    }

    /**
     * Provision an officer's account and post them to the department.
     *
     * **The posting is an assignment, not a column** (change item 2, 2026-08-24).
     * The account carries no department of its own, so `assign()` is what makes
     * this officer an Authorized Officer *of Higher Education*, and it is also
     * what grants the role — which is why nothing here calls `assignRole()`.
     * Registration is the officer's first posting; a later transfer is the same
     * operation through the same service, and that is the point.
     *
     * @param  array{name:string,designation_id:int,mobile:string,email:string}  $attributes
     */
    private function createOfficer(Department $department, array $attributes, Role $role): User
    {
        $password = Str::password(12);

        $user = new User([
            'name' => $attributes['name'],
            'designation_id' => $attributes['designation_id'],
            'mobile' => $attributes['mobile'],
            'email' => $attributes['email'],
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->password = Hash::make($password);
        $user->save();

        $this->assignments->assign(
            $user,
            $department,
            $role,
            remarks: 'Provisioned by department registration (S6).',
        );

        CredentialsIssued::dispatch($user, $password);

        return $user;
    }
}
