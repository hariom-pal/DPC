<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\DepartmentStatus;
use App\Models\Department;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class]);
    }

    public function test_valid_otps_verify_the_account_and_activate_the_department(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();

        $this->post(route('register.store'), [
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
        ]);

        $this->post(route('verify.store'), [
            'email_code' => '123456',
            'mobile_code' => '123456',
        ])->assertRedirect(route('login'));

        $ao = $department->users()->where('level', 1)->firstOrFail();
        $this->assertTrue($ao->fresh()->isFullyVerified());
        $this->assertSame(DepartmentStatus::Active, $department->fresh()->status);
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $department = Department::query()->where('code', 'HE')->firstOrFail();

        $this->post(route('register.store'), [
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
        ]);

        $this->post(route('verify.store'), [
            'email_code' => '000000',
            'mobile_code' => '000000',
        ])->assertSessionHasErrors('mobile_code');

        $ao = $department->users()->where('level', 1)->firstOrFail();
        $this->assertFalse($ao->fresh()->isFullyVerified());
    }
}
