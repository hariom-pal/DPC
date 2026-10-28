<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Portal Super Admin's account management (business decision, 2026-10-26).
 */
class PortalAdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $portalAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->portalAdmin = User::factory()->create();
        $this->portalAdmin->assignRole(Role::Admin->value);
    }

    private function shownPassword(string $html): string
    {
        preg_match('#<code class="fs-5" id="issued-password">([^<]+)</code>#', $html, $match);
        $this->assertNotEmpty($match);

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    public function test_the_super_admin_resets_a_department_admin_and_sees_the_new_password_once(): void
    {
        $department = Department::query()->create(['code' => 'PHE', 'name' => 'PHE', 'status' => DepartmentStatus::Registered->value]);
        $deptAdmin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();

        $response = $this->actingAs($this->portalAdmin)->post(route('admin.users.reset-password', $deptAdmin));
        $response->assertOk()->assertSee(__('account.issued.previous_invalid'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $password = $this->shownPassword((string) $response->getContent());

        $this->assertTrue(Hash::check($password, $deptAdmin->fresh()->getAuthPassword()));
        $this->assertFalse(Hash::check('password', $deptAdmin->fresh()->getAuthPassword()));
        $this->assertTrue($deptAdmin->fresh()->must_change_password);
        $this->assertNull(session('issuedPassword'));

        // A second reset issues another NEW password.
        $again = $this->shownPassword((string) $this->actingAs($this->portalAdmin)->post(route('admin.users.reset-password', $deptAdmin))->getContent());
        $this->assertNotSame($password, $again);
    }

    public function test_the_super_admin_creates_a_department_admin_and_sees_its_password(): void
    {
        $department = Department::query()->create(['code' => 'PHE', 'name' => 'PHE', 'status' => DepartmentStatus::Registered->value]);

        $response = $this->actingAs($this->portalAdmin)->post(route('admin.users.store'), [
            'date_of_birth' => '1979-09-09', 'gender' => 'M', // checked against DigiLocker (2026-10-27)
            'name' => 'Shri D. Admin', 'email' => 'deptadmin@cg.gov.in', 'mobile' => '9000000601',
            'role' => Role::DepartmentAdmin->value, 'department_id' => $department->id,
        ]);
        $response->assertSessionHasNoErrors()->assertOk();
        $password = $this->shownPassword((string) $response->getContent());

        $user = User::query()->where('email', 'deptadmin@cg.gov.in')->sole();
        $this->assertTrue($user->hasRole(Role::DepartmentAdmin->value));
        $this->assertSame($department->id, $user->department_id);
        $this->assertTrue(Hash::check($password, $user->getAuthPassword()));
    }

    public function test_only_the_super_admin_reaches_these_screens(): void
    {
        $department = Department::query()->create(['code' => 'PHE', 'name' => 'PHE', 'status' => DepartmentStatus::Registered->value]);
        $deptAdmin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();

        $this->actingAs($deptAdmin)->post(route('admin.users.reset-password', $this->portalAdmin))->assertForbidden();
        $this->actingAs($deptAdmin)->get(route('admin.users.index'))->assertForbidden();
    }
}
