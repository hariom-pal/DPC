<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\RegistrationService;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Illuminate\Database\Seeder;

/**
 * Demo accounts: the CGPSC side (Admin, Chairman, five Members) and the department
 * side — an Authorized Officer and an Administrative Head for **the one** demo
 * department. See {@see self::DEPARTMENTS} for why there is only one.
 *
 * The department accounts are created by running the **real registration
 * service** (PPT S5–S6) rather than by inserting rows: the department moves
 * from `unregistered` to `registered`, both officers get their levels and
 * roles, and the department contact details are captured exactly as they would
 * be if someone had filled the registration form. The only thing the seeder
 * changes afterwards is the credentials — a demo cannot use a random password
 * that was emailed to nobody, and cannot stop at an OTP screen.
 */
class DemoAccountSeeder extends Seeder
{
    /**
     * One password for every demo account. Meets the portal's own change-password
     * policy (8+, mixed case, digit, symbol), so an account can be used to
     * demonstrate the change-password screen without first being reset.
     */
    public const PASSWORD = 'Demo@2026';

    /**
     * The one demo department, keyed by master code.
     *
     * One department, deliberately. Every DPC, every master and every officer in
     * the demo database belongs to School Education, so a presenter never has
     * to explain why the record on screen is in a different department from the
     * one they just showed. The department master itself still lists all nine
     * departments — that list is what the registration dropdown is drawn from,
     * and being able to show it is the point of it.
     *
     * @var array<string, array{name:string, district:string, place:string, pin:string, email:string, ao:array{name:string,designation:string,mobile:string,email:string}, ah:array{name:string,designation:string,mobile:string,email:string}}>
     */
    public const DEPARTMENTS = [
        'SE' => [
            'name' => 'School Education Department',
            'district' => 'Raipur',
            'place' => 'Indravati Bhawan, Naya Raipur Atal Nagar',
            'pin' => '492002',
            'email' => 'schooleducation@cg.gov.in',
            'ao' => [
                'name' => 'Shri Anil Kumar Verma',
                'designation' => 'Under Secretary',
                'mobile' => '9000001101',
                'email' => 'ao.schooleducation@cg.gov.in',
            ],
            'ah' => [
                'name' => 'Smt. Sunita Rathore',
                'designation' => 'Secretary',
                'mobile' => '9000001102',
                'email' => 'ah.schooleducation@cg.gov.in',
            ],
        ],
    ];

    /**
     * One Chairman, who allots every DPC in the demo.
     *
     * @var list<array{name:string, email:string, mobile:string, designation:string}>
     */
    public const CHAIRMEN = [
        ['name' => 'Dr. Ajay Kumar Shrivastava', 'email' => 'chairman@cgpsc.gov.in', 'mobile' => '9000000101', 'designation' => 'Chairman, CGPSC'],
    ];

    /**
     * Five Members, because the demo needs five distinct Commission-side
     * situations on screen at once and a Member's queue is personal to them:
     * one who has concluded a DPC and its Review, one who has concluded a DPC
     * and signs a Circulation, one with an allotment still to accept, one with
     * a sitting scheduled and not yet opened, and one with a sitting open. Which
     * is which is recorded in the cheat sheet.
     *
     * @var list<array{name:string, email:string, mobile:string, designation:string}>
     */
    public const MEMBERS = [
        ['name' => 'Shri Brijesh Kumar Pandey', 'email' => 'member1@cgpsc.gov.in', 'mobile' => '9000000201', 'designation' => 'Member, CGPSC'],
        ['name' => 'Smt. Kavita Deshmukh', 'email' => 'member2@cgpsc.gov.in', 'mobile' => '9000000202', 'designation' => 'Member, CGPSC'],
        ['name' => 'Dr. Suresh Chandra Netam', 'email' => 'member3@cgpsc.gov.in', 'mobile' => '9000000203', 'designation' => 'Member, CGPSC'],
        ['name' => 'Shri Mohan Lal Kashyap', 'email' => 'member4@cgpsc.gov.in', 'mobile' => '9000000204', 'designation' => 'Member, CGPSC'],
        ['name' => 'Smt. Anita Bhonsle', 'email' => 'member5@cgpsc.gov.in', 'mobile' => '9000000205', 'designation' => 'Member, CGPSC'],
    ];

    public function run(RegistrationService $registration): void
    {
        $this->seedDesignations();

        $this->cgpscAccount(
            (string) config('dpc.admin.name'),
            (string) config('dpc.admin.email'),
            (string) config('dpc.admin.mobile'),
            'System Administrator',
            Role::Admin,
        );

        foreach (self::CHAIRMEN as $chairman) {
            $this->cgpscAccount($chairman['name'], $chairman['email'], $chairman['mobile'], $chairman['designation'], Role::Chairman);
        }

        foreach (self::MEMBERS as $member) {
            $this->cgpscAccount($member['name'], $member['email'], $member['mobile'], $member['designation'], Role::Member);
        }

        foreach (self::DEPARTMENTS as $code => $spec) {
            $this->registerDepartment($registration, $code, $spec);
        }
    }

    /**
     * Designations the demo needs beyond the base seeder's secretariat list —
     * the posts the demo officers and committee members actually hold.
     */
    private function seedDesignations(): void
    {
        $names = [
            'Chairman, CGPSC', 'Member, CGPSC', 'System Administrator',
            'Director, Public Instruction', 'Additional Director, Education',
            'Joint Director, Education', 'District Education Officer',
            'Principal, Higher Secondary School', 'Block Education Officer',
            'Lecturer', 'Head Master, Middle School', 'Teacher',

            /*
             | The ranks the demo committee members hold. Added 2026-09-06, when
             | the committee screen stopped accepting a typed designation: the
             | dropdown can only offer what the master holds, and the demo script
             | names these three by name.
             |
             | The qualified forms, not the bare "Secretary" the base seeder
             | already carries — a committee minute has to say *which* Secretary.
             */
            'Chairman, Chhattisgarh Public Service Commission',
            'Secretary, School Education',
            'Deputy Secretary, GAD',
        ];

        foreach ($names as $name) {
            Designation::query()->updateOrCreate(
                ['name' => $name],
                ['status' => MasterStatus::Active->value],
            );
        }
    }

    private function cgpscAccount(string $name, string $email, string $mobile, string $designation, Role $role): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'mobile' => $mobile,
                'designation_id' => Designation::query()->where('name', $designation)->value('id'),
                'password' => self::PASSWORD,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
                'mobile_verified_at' => now(),
            ],
        );

        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function registerDepartment(RegistrationService $registration, string $code, array $spec): void
    {
        $department = Department::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $spec['name'], 'status' => DepartmentStatus::Unregistered->value],
        );

        // Already registered by a previous run of this seeder — leave the
        // registration alone and only re-assert the demo credentials.
        if ($department->users()->exists()) {
            $department->users->each(fn (User $user) => $this->demoCredentials($user));

            return;
        }

        $registration->register([
            'department_id' => $department->id,
            'district_id' => (int) District::query()->where('name_en', $spec['district'])->value('id'),
            'place' => $spec['place'],
            'pin' => $spec['pin'],
            'department_email' => $spec['email'],
            'ao_name' => $spec['ao']['name'],
            'ao_designation_id' => (int) Designation::query()->where('name', $spec['ao']['designation'])->value('id'),
            'ao_mobile' => $spec['ao']['mobile'],
            'ao_email' => $spec['ao']['email'],
            'ah_name' => $spec['ah']['name'],
            'ah_designation_id' => (int) Designation::query()->where('name', $spec['ah']['designation'])->value('id'),
            'ah_mobile' => $spec['ah']['mobile'],
            'ah_email' => $spec['ah']['email'],
        ]);

        $department->refresh()->load('users');
        $department->users->each(fn (User $user) => $this->demoCredentials($user));
    }

    /**
     * Registration issues a random password and leaves the account unverified,
     * which is right in production and useless on a projector. This replaces
     * the credentials with the shared demo password and marks both channels
     * verified so the account signs straight in.
     */
    private function demoCredentials(User $user): void
    {
        $user->forceFill([
            'password' => self::PASSWORD,
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'mobile_verified_at' => $user->mobile_verified_at ?? now(),
            'is_active' => true,
        ])->save();
    }
}
