<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class, DistrictSeeder::class, DesignationSeeder::class]);
    }

    /**
     * @return array<string, string|int>
     */
    private function payload(Department $department): array
    {
        return [
            'department_id' => $department->id,
            'district_id' => (int) District::query()->where('name_en', 'Raipur')->value('id'),
            'place' => 'Raipur',
            'pin' => '492001',
            'department_email' => 'h_edu@cg.gov.in',
            'ah_name' => 'A. B. Cdef',
            'ah_designation_id' => (int) Designation::query()->where('name', 'Secretary')->value('id'),
            'ah_mobile' => '9826195455',
            'ah_email' => 'ah.hedu@cg.gov.in',
            'ao_name' => 'P. Q. Rstuv',
            'ao_designation_id' => (int) Designation::query()->where('name', 'Deputy Secretary')->value('id'),
            'ao_mobile' => '9407938480',
            'ao_email' => 'ao.hedu@cg.gov.in',
        ];
    }

    public function test_valid_otps_verify_the_account_and_activate_the_department(): void
    {
        $department = Department::query()->where('code', 'SE')->firstOrFail();
        $this->post(route('register.store'), $this->payload($department));

        $this->post(route('verify.store'), [
            'email_code' => '123456',
            'mobile_code' => '123456',
        ])->assertRedirect(route('login'));

        $ao = $department->users()->role(Role::AuthorizedOfficer->value)->firstOrFail();
        $this->assertTrue($ao->fresh()->isFullyVerified());
        $this->assertSame(DepartmentStatus::Active, $department->fresh()->status);
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $department = Department::query()->where('code', 'SE')->firstOrFail();
        $this->post(route('register.store'), $this->payload($department));

        $this->post(route('verify.store'), [
            'email_code' => '000000',
            'mobile_code' => '000000',
        ])->assertSessionHasErrors('mobile_code');

        $ao = $department->users()->role(Role::AuthorizedOfficer->value)->firstOrFail();
        $this->assertFalse($ao->fresh()->isFullyVerified());
    }
}
