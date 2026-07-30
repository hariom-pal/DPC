<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Enums\UserLevel;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Department registration (PPT S5–S6).
 *
 * Registers a department and provisions its two-tier accounts — Authorized
 * Officer (Level-1) and Administrative Head (Level-2) — then issues login
 * credentials over mobile + email and starts OTP verification. The Authorized
 * Officer is the registrant and holds the department's .gov email; both
 * officers log in with their (unique) mobile number.
 */
class RegistrationService
{
    public function __construct(private readonly SmsService $sms) {}

    /**
     * Register the department, provision AO + AH accounts, issue credentials.
     * Returns the Authorized Officer (registrant) so the caller can start
     * OTP verification.
     *
     * @param  array{department_id:int,district:string,place:string,pin:string,email:string,ao_name:string,ao_designation:string,ao_mobile:string,ah_name:string,ah_designation:string,ah_mobile:string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $department = Department::query()->findOrFail($data['department_id']);

            $department->forceFill([
                'district' => $data['district'],
                'place' => $data['place'],
                'pin' => $data['pin'],
                'email' => $data['email'],
                'status' => DepartmentStatus::Registered->value,
                'registered_at' => now(),
            ])->save();

            $aoPassword = Str::password(12);
            $ao = $this->createOfficer($department, [
                'name' => $data['ao_name'],
                'designation' => $data['ao_designation'],
                'mobile' => $data['ao_mobile'],
                'email' => $data['email'],
            ], UserLevel::AuthorizedOfficer, Role::AuthorizedOfficer, $aoPassword);

            $ahPassword = Str::password(12);
            $ah = $this->createOfficer($department, [
                'name' => $data['ah_name'],
                'designation' => $data['ah_designation'],
                'mobile' => $data['ah_mobile'],
                'email' => null,
            ], UserLevel::AdministrativeHead, Role::AdministrativeHead, $ahPassword);

            $this->issueCredentials($ao, $aoPassword);
            $this->issueCredentials($ah, $ahPassword);

            return $ao;
        });
    }

    /**
     * @param  array{name:string,designation:string,mobile:string,email:?string}  $attributes
     */
    private function createOfficer(Department $department, array $attributes, UserLevel $level, Role $role, string $password): User
    {
        $user = new User([
            'name' => $attributes['name'],
            'designation' => $attributes['designation'],
            'mobile' => $attributes['mobile'],
            'email' => $attributes['email'],
            'level' => $level->value,
            'is_active' => true,
        ]);
        $user->password = Hash::make($password);
        $user->department()->associate($department);
        $user->save();
        $user->assignRole($role->value);

        return $user;
    }

    private function issueCredentials(User $user, string $password): void
    {
        $loginId = $user->mobile;
        $message = "ODPC Portal account created. Login ID: {$loginId}. Temporary password: {$password}. "
            .'Please log in and complete OTP verification.';

        if ($user->mobile !== null) {
            $this->sms->send($user->mobile, $message);
        }

        if ($user->email !== null) {
            Mail::raw($message, function ($mail) use ($user): void {
                $mail->to($user->email)->subject('ODPC Portal — Account Credentials');
            });
        }
    }
}
