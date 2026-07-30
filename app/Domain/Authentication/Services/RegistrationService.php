<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Enums\UserLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Department registration (PPT S5–S6).
 *
 * Registers a department and provisions its two-tier accounts — Authorized
 * Officer (Level-1) and Administrative Head (Level-2) — then issues login
 * credentials (queued, via the CredentialsIssued event). Each officer has
 * their own official email and mobile; the department contact email is stored
 * separately on the department. Officers log in with email or mobile and must
 * change the system-issued password on first login.
 */
class RegistrationService
{
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
            ], UserLevel::AuthorizedOfficer, Role::AuthorizedOfficer);

            $ah = $this->createOfficer($department, [
                'name' => $data['ah_name'],
                'designation_id' => $data['ah_designation_id'],
                'mobile' => $data['ah_mobile'],
                'email' => $data['ah_email'],
            ], UserLevel::AdministrativeHead, Role::AdministrativeHead);

            return $ao;
        });
    }

    /**
     * @param  array{name:string,designation_id:int,mobile:string,email:string}  $attributes
     */
    private function createOfficer(Department $department, array $attributes, UserLevel $level, Role $role): User
    {
        $password = Str::password(12);

        $user = new User([
            'name' => $attributes['name'],
            'designation_id' => $attributes['designation_id'],
            'mobile' => $attributes['mobile'],
            'email' => $attributes['email'],
            'level' => $level->value,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->password = Hash::make($password);
        $user->department()->associate($department);
        $user->save();
        $user->assignRole($role->value);

        CredentialsIssued::dispatch($user, $password);

        return $user;
    }
}
