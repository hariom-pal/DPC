<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\OtpVerification;
use App\Models\User;
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
            'email' => 'h_edu@cg.gov.in',
            'ah_name' => 'A. B. Cdef',
            'ah_designation' => 'Secretary',
            'ah_mobile' => '9826195455',
            'ao_name' => 'P. Q. Rstuv',
            'ao_designation' => 'Deputy Secretary',
            'ao_mobile' => '9407938480',
        ];
    }

    public function test_department_registration_provisions_two_accounts_and_issues_otps(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();

        $this->post(route('register.store'), $this->validPayload($department))
            ->assertRedirect(route('verify.show'));

        $department->refresh();
        $this->assertSame(DepartmentStatus::Registered, $department->status);

        $ao = User::query()->where('mobile', '9407938480')->firstOrFail();
        $ah = User::query()->where('mobile', '9826195455')->firstOrFail();

        $this->assertTrue($ao->hasRole(Role::AuthorizedOfficer->value));
        $this->assertTrue($ah->hasRole(Role::AdministrativeHead->value));
        $this->assertSame(1, $ao->level?->value);
        $this->assertSame(2, $ah->level?->value);
        $this->assertFalse($ao->isFullyVerified());

        // Registration OTPs: department email + AO mobile.
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'h_edu@cg.gov.in')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9407938480')->count());
    }

    public function test_registration_rejects_non_government_email(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();
        $payload = $this->validPayload($department);
        $payload['email'] = 'someone@gmail.com';

        $this->post(route('register.store'), $payload)
            ->assertSessionHasErrors('email');

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
