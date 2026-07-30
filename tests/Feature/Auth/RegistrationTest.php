<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class]);
    }

    /**
     * @return array<string, string|int>
     */
    private function validPayload(Department $department): array
    {
        return [
            'department_id' => $department->id,
            'district' => 'Raipur',
            'place' => 'Raipur',
            'pin' => '492001',
            'department_email' => 'h_edu@cg.gov.in',
            'ah_name' => 'A. B. Cdef',
            'ah_designation' => 'Secretary',
            'ah_mobile' => '9826195455',
            'ah_email' => 'ah.hedu@cg.gov.in',
            'ao_name' => 'P. Q. Rstuv',
            'ao_designation' => 'Deputy Secretary',
            'ao_mobile' => '9407938480',
            'ao_email' => 'ao.hedu@cg.gov.in',
        ];
    }

    public function test_department_registration_provisions_two_accounts_and_issues_otps(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();

        $this->post(route('register.store'), $this->validPayload($department))
            ->assertRedirect(route('verify.show'));

        $department->refresh();
        $this->assertSame(DepartmentStatus::Registered, $department->status);
        $this->assertSame('h_edu@cg.gov.in', $department->email);

        $ao = User::query()->where('mobile', '9407938480')->firstOrFail();
        $ah = User::query()->where('mobile', '9826195455')->firstOrFail();

        $this->assertTrue($ao->hasRole(Role::AuthorizedOfficer->value));
        $this->assertTrue($ah->hasRole(Role::AdministrativeHead->value));
        $this->assertSame('ao.hedu@cg.gov.in', $ao->email);
        $this->assertSame('ah.hedu@cg.gov.in', $ah->email);
        $this->assertTrue($ao->must_change_password);
        $this->assertFalse($ao->isFullyVerified());

        // Registration OTPs for the registrant: AO email + AO mobile.
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'ao.hedu@cg.gov.in')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9407938480')->count());
    }

    public function test_registration_rejects_non_government_email(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();
        $payload = $this->validPayload($department);
        $payload['ao_email'] = 'someone@gmail.com';

        $this->post(route('register.store'), $payload)
            ->assertSessionHasErrors('ao_email');

        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_rejects_identical_officer_mobiles(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();
        $payload = $this->validPayload($department);
        $payload['ah_mobile'] = $payload['ao_mobile'];

        $this->post(route('register.store'), $payload)
            ->assertSessionHasErrors('ao_mobile');
    }
}
