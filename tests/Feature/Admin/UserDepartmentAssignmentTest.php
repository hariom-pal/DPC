<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Notification\Services\NotificationRecipientResolver;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Change item 2: an officer's department is a dated posting, not a property of
 * their account.
 *
 * The register's worked example is the spine of this file — Hariom Pal is an AO
 * in Higher Education today and an AO in Forest tomorrow, and it is the same
 * person with the same login. Every test below asserts one half of that: the
 * login survives, the history survives, and nothing about the old department
 * survives *as current*.
 */
class UserDepartmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $higherEducation;

    private Department $forest;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Freeze the clock before any fixture exists.
         |
         | `assignedTo()` posts the officer with effect from *today*, and the
         | tests below transfer them on 2026-09-01. Left on the real calendar
         | that is a comparison between a hardcoded date and whatever day the
         | suite happens to run, so the file passed until the real date reached
         | 2026-09-01 and the fixture posting began on the very day of the
         | transfer — which the business rule rightly refuses, because a
         | transfer must take effect after the posting it closes. Pinning the
         | fixture date makes the interval explicit and the suite date-proof.
         */
        $this->travelTo(Carbon::parse('2026-08-01'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->higherEducation = Department::query()->create([
            'code' => 'HED', 'name' => 'Higher Education Department', 'status' => DepartmentStatus::Registered->value,
        ]);
        $this->forest = Department::query()->create([
            'code' => 'FOR', 'name' => 'Forest Department', 'status' => DepartmentStatus::Registered->value,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    private function service(): DepartmentAssignmentService
    {
        return app(DepartmentAssignmentService::class);
    }

    public function test_a_transfer_keeps_the_same_account_and_closes_the_previous_posting(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create([
            'name' => 'Hariom Pal', 'email' => 'hariom.pal@cg.gov.in',
        ]);

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->assign($officer, $this->forest, Role::AuthorizedOfficer);

        $officer->refresh()->unsetRelation('activeAssignment');

        // The same account, the same login.
        $this->assertSame(1, User::query()->where('email', 'hariom.pal@cg.gov.in')->count());
        $this->assertSame('hariom.pal@cg.gov.in', $officer->email);

        // Now in Forest.
        $this->assertSame($this->forest->id, $officer->department_id);
        $this->assertSame('Forest Department', $officer->department?->name);

        // The Higher Education posting is closed, dated out, and still there.
        $this->assertSame(2, $officer->assignments()->count());

        $closed = $officer->assignments()->where('department_id', $this->higherEducation->id)->sole();
        $this->assertFalse($closed->isActive());
        $this->assertSame('2026-08-31', $closed->effective_to?->toDateString());
    }

    public function test_the_database_refuses_a_second_active_posting(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->expectException(QueryException::class);

        // Deliberately bypassing the service: the guarantee has to hold even
        // against code that does not go through it, which is why it is a unique
        // index and not a check in a method.
        UserDepartmentAssignment::query()->create([
            'user_id' => $officer->id,
            'department_id' => $this->forest->id,
            'role' => Role::AuthorizedOfficer->value,
            'effective_from' => Carbon::today(),
            'active_flag' => 1,
        ]);
    }

    public function test_any_number_of_closed_postings_may_coexist(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->assign($officer, $this->forest, Role::AuthorizedOfficer);

        $this->travelTo(Carbon::parse('2026-10-01'));
        $this->service()->assign($officer, $this->higherEducation, Role::AdministrativeHead);

        $this->assertSame(3, $officer->assignments()->count());
        $this->assertSame(1, $officer->assignments()->whereNotNull('active_flag')->count());
    }

    public function test_the_role_follows_the_posting(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation, Role::AuthorizedOfficer)->create();
        $this->assertTrue($officer->hasRole(Role::AuthorizedOfficer->value));

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->assign($officer, $this->higherEducation, Role::AdministrativeHead);

        $officer->refresh()->unsetRelation('roles');

        $this->assertTrue($officer->hasRole(Role::AdministrativeHead->value));

        // The point of the test: the officer is no longer an Authorized Officer.
        // Left behind, they could still file the department's proposals.
        $this->assertFalse($officer->hasRole(Role::AuthorizedOfficer->value));
    }

    public function test_release_closes_the_posting_and_removes_the_role_but_keeps_the_account(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->release($officer);

        $officer->refresh()->unsetRelation('activeAssignment');
        $officer->unsetRelation('roles');

        $this->assertNull($officer->department_id);
        $this->assertFalse($officer->hasRole(Role::AuthorizedOfficer->value));

        // Still an account, still active, still with its history.
        $this->assertTrue($officer->is_active);
        $this->assertSame(1, $officer->assignments()->count());
    }

    public function test_a_commission_role_is_not_stripped_by_releasing_a_posting(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();
        $officer->assignRole(Role::Member->value);

        $this->service()->release($officer);

        $officer->refresh()->unsetRelation('roles');

        // No posting granted the Commission role, so no release can take it away.
        $this->assertTrue($officer->hasRole(Role::Member->value));
        $this->assertFalse($officer->hasRole(Role::AuthorizedOfficer->value));
    }

    public function test_notifications_follow_the_officer_out_of_the_department_immediately(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();
        $resolver = app(NotificationRecipientResolver::class);

        $before = $resolver->resolve($this->higherEducation->id, [Role::AuthorizedOfficer->value]);
        $this->assertTrue($before->contains('id', $officer->id));

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->assign($officer, $this->forest, Role::AuthorizedOfficer);

        $after = $resolver->resolve($this->higherEducation->id, [Role::AuthorizedOfficer->value]);
        $this->assertFalse(
            $after->contains('id', $officer->id),
            'A transferred officer was still being notified about their former department.',
        );

        $now = $resolver->resolve($this->forest->id, [Role::AuthorizedOfficer->value]);
        $this->assertTrue($now->contains('id', $officer->id));
    }

    public function test_the_department_lists_only_officers_currently_posted_to_it(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->service()->assign($officer, $this->forest, Role::AuthorizedOfficer);

        $this->assertSame(0, $this->higherEducation->users()->count());
        $this->assertSame(1, $this->forest->users()->count());

        // The posting itself is still on record against the old department.
        $this->assertSame(1, $this->higherEducation->assignments()->count());
    }

    public function test_the_admin_screen_records_a_transfer_and_shows_the_history(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create(['name' => 'Hariom Pal']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.assignments.store', $officer), [
                'department_id' => $this->forest->id,
                'role' => Role::AuthorizedOfficer->value,
                'effective_from' => '2026-09-01',
                'remarks' => 'Transfer order 1234/2026',
            ])
            ->assertRedirect(route('admin.users.assignments.index', $officer));

        $this->actingAs($this->admin)
            ->get(route('admin.users.assignments.index', $officer))
            ->assertOk()
            ->assertSee('Forest Department')
            ->assertSee('Higher Education Department')
            ->assertSee('Transfer order 1234/2026');
    }

    public function test_a_transfer_dated_before_the_current_posting_is_refused(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.users.assignments.store', $officer), [
                'department_id' => $this->forest->id,
                'role' => Role::AuthorizedOfficer->value,
                'effective_from' => Carbon::today()->subYear()->toDateString(),
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertSame($this->higherEducation->id, $officer->refresh()->department_id);
    }

    public function test_re_posting_to_the_same_department_and_role_is_refused(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.users.assignments.store', $officer), [
                'department_id' => $this->higherEducation->id,
                'role' => Role::AuthorizedOfficer->value,
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertSame(1, $officer->assignments()->count());
    }

    public function test_provisioning_a_department_role_requires_a_department(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Shri A. Officer',
                'email' => 'a.officer@cg.gov.in',
                'mobile' => '9800000001',
                'role' => Role::AuthorizedOfficer->value,
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('users', ['email' => 'a.officer@cg.gov.in']);
    }

    public function test_provisioning_a_commission_role_refuses_a_department(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Hon. Member',
                'email' => 'member@cgpsc.gov.in',
                'mobile' => '9800000002',
                'role' => Role::Member->value,
                'department_id' => $this->forest->id,
            ])
            ->assertSessionHasErrors('department_id');
    }

    public function test_provisioning_a_department_role_posts_the_officer(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Shri A. Officer',
                'email' => 'a.officer@cg.gov.in',
                'mobile' => '9800000003',
                'role' => Role::AdministrativeHead->value,
                'department_id' => $this->forest->id,
            ])
            ->assertRedirect(route('admin.users.index'));

        $officer = User::query()->where('email', 'a.officer@cg.gov.in')->sole();

        $this->assertSame($this->forest->id, $officer->department_id);
        $this->assertTrue($officer->hasRole(Role::AdministrativeHead->value));
        $this->assertSame(Role::AdministrativeHead, $officer->assigned_role);
    }

    public function test_moving_an_officer_to_a_commission_role_releases_the_posting(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create(['email' => 'x@cg.gov.in']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $officer), [
                'name' => $officer->name,
                'email' => 'x@cg.gov.in',
                'mobile' => $officer->mobile,
                'role' => Role::Chairman->value,
            ])
            ->assertRedirect(route('admin.users.index'));

        $officer->refresh()->unsetRelation('activeAssignment');
        $officer->unsetRelation('roles');

        $this->assertNull($officer->department_id);
        $this->assertTrue($officer->hasRole(Role::Chairman->value));
        $this->assertFalse($officer->hasRole(Role::AuthorizedOfficer->value));

        // The posting is closed, not erased.
        $this->assertSame(1, $officer->assignments()->count());
    }

    public function test_only_the_administrator_reaches_role_assignments(): void
    {
        $officer = User::factory()->assignedTo($this->higherEducation)->create();

        $this->actingAs($officer)
            ->get(route('admin.users.assignments.index', $officer))
            ->assertForbidden();
    }
}
