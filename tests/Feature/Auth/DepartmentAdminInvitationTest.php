<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Department\Models\DepartmentInvitation;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReadsSentMail;
use Tests\TestCase;

/**
 * One Department Admin per department, invited at the department's own e-mail
 * (2026-10-28). Onboarding is e-mail OTP, mobile OTP and DigiLocker (simulated
 * here — not proof of the real DigiLocker), which supplies the person's name,
 * date of birth and gender. A fresh invitation hands the department's account
 * to the newly invited person.
 */
class DepartmentAdminInvitationTest extends TestCase
{
    use ReadsSentMail;
    use RefreshDatabase;

    private User $superAdmin;

    private Department $department;

    private Department $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DistrictSeeder::class]);
        config(['dpc.otp.fixed.mobile' => true]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole(Role::Admin->value);

        $district = District::query()->where('name_en', 'Raipur')->value('id');
        $this->department = Department::query()->create([
            'code' => 'PHE', 'name' => 'Public Health Engineering Department', 'status' => DepartmentStatus::Unregistered->value,
            'district_id' => $district, 'place' => 'Nava Raipur', 'pin' => '492002', 'email' => 'phe.dpc@cg.gov.in',
        ]);
        $this->other = Department::query()->create([
            'code' => 'HED', 'name' => 'Higher Education Department', 'status' => DepartmentStatus::Unregistered->value,
            'district_id' => $district, 'place' => 'Nava Raipur', 'pin' => '492002', 'email' => 'he.dpc@cg.gov.in',
        ]);
    }

    private function invite(string $email = 'phe.dpc@cg.gov.in'): TestResponse
    {
        $this->flushSentMail();

        return $this->actingAs($this->superAdmin)->post(route('admin.department-invitations.store'), ['department_id' => $this->department->id, 'email' => $email]);
    }

    private function token(): string
    {
        $mail = collect($this->sentMail())->last(fn (array $m): bool => str_contains($m['body'], '/register/'));
        $this->assertNotNull($mail, 'No invitation e-mail was sent.');
        preg_match('#/register/([A-Za-z0-9]{64})#', $mail['body'], $match);

        return $match[1];
    }

    /**
     * @param  array<string, string>  $identity
     */
    private function register(string $token, string $mobile = '9000000401', array $identity = []): User
    {
        auth()->logout();
        $this->travel(2)->minutes(); // past the registration and OTP rate limits between registrations
        $this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => $mobile])->assertRedirect(route('register.invitation.verify', ['token' => $token]));
        $this->post(route('register.invitation.verify.store', ['token' => $token]), ['email_code' => '123456', 'mobile_code' => '123456'])
            ->assertRedirect(route('register.invitation.identity', ['token' => $token]));

        $start = $this->post(route('register.invitation.identity.start', ['token' => $token]));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = $this->post(route('identity.simulator.submit'), array_replace([
            'state' => (string) ($query['state'] ?? ''), 'decision' => 'allow',
            'name' => 'Smt. Kavita Sahu', 'date_of_birth' => '1979-02-14', 'gender' => 'F', 'aadhaar_verified' => '1',
        ], $identity));
        $this->get((string) $callback->headers->get('Location'))->assertRedirect(route('register.invitation.password', ['token' => $token]));
        $this->post(route('register.invitation.complete', ['token' => $token]), ['password' => 'Dept@Admin2026', 'password_confirmation' => 'Dept@Admin2026', 'designation_id' => (string) Designation::query()->firstOrCreate(['name' => 'Deputy Secretary'], ['status' => 'active'])->id])
            ->assertRedirect(route('login'));

        return User::query()->where('email', 'phe.dpc@cg.gov.in')->sole();
    }

    public function test_the_invitation_carries_only_the_department_and_its_own_email(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.department-invitations.index'))
            ->assertOk()->assertSee('data-email="phe.dpc@cg.gov.in"', false)->assertDontSee('waive_mobile_otp', false);
        $this->invite()->assertOk();

        $invitation = DepartmentInvitation::query()->sole();
        $this->assertSame([$this->department->id, 'phe.dpc@cg.gov.in', false], [$invitation->department_id, $invitation->email, $invitation->mobile_otp_waived]);
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $invitation->expires_at->timestamp, 5);
        foreach (['name', 'date_of_birth', 'gender', 'designation_id', 'mobile', 'pre_dpc_proposal_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('department_invitations', $column), $column);
        }
        $this->assertFalse(Schema::hasTable('dpc_administrators'));
    }

    public function test_another_departments_email_is_refused_before_anything_is_sent(): void
    {
        $this->invite('he.dpc@cg.gov.in')->assertSessionHasErrors(['email' => __('invitations.errors.other_department_email', ['department' => 'Higher Education Department'])]);
        $this->invite('kavita.sahu@cg.gov.in')->assertSessionHasErrors(['email' => __('invitations.errors.not_department_email', ['email' => 'phe.dpc@cg.gov.in'])]);
        $this->invite('PHE.DPC@cg.gov.in')->assertOk(); // the case of the address does not matter

        $this->assertSame(1, DepartmentInvitation::query()->count());
        $this->assertCount(1, $this->sentMail());
    }

    public function test_an_officers_account_email_is_never_taken_over(): void
    {
        $this->department->forceFill(['email' => 'officer.own@cg.gov.in'])->save();
        $officer = User::factory()->create(['email' => 'officer.own@cg.gov.in']);
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->invite('officer.own@cg.gov.in')->assertSessionHasErrors(['email' => __('invitations.errors.email_in_use')]);
    }

    public function test_one_pending_invitation_per_department(): void
    {
        $this->invite()->assertOk();
        $this->invite()->assertSessionHasErrors(['email' => __('invitations.errors.already_pending')]);
    }

    public function test_the_department_admin_onboards_with_both_otps_and_digilocker(): void
    {
        $this->invite();
        $admin = $this->register($this->token());

        $this->assertSame(['Smt. Kavita Sahu', '1979-02-14', 'F'], [$admin->name, $admin->date_of_birth?->toDateString(), $admin->gender], 'From DigiLocker.');
        $this->assertNotNull($admin->mobile_verified_at);
        $this->assertNull($admin->mobile_verification_waived_at);
        $this->assertSame(IdentityVerification::OUTCOME_VERIFIED, IdentityVerification::query()->where('user_id', $admin->id)->sole()->outcome);
        $this->assertTrue(app(DepartmentUserAuthority::class)->isDepartmentAdmin($admin));
    }

    public function test_a_new_department_admin_replaces_and_deactivates_the_previous_one(): void
    {
        // An earlier Department Admin of the department, on another account.
        $previous = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create(['email' => 'old.admin@cg.gov.in']);

        $this->invite();
        $admin = $this->register($this->token());

        $previous->refresh();
        $this->assertFalse($previous->is_active, 'Deactivated.');
        $this->assertGreaterThan(0, (int) $previous->session_epoch, 'Every session ended.');
        $this->assertSame(0, UserDepartmentAssignment::query()->current()->where('user_id', $previous->id)->count());
        $this->assertDatabaseHas('user_department_assignments', ['user_id' => $previous->id, 'department_id' => $this->department->id, 'role' => Role::DepartmentAdmin->value, 'active_flag' => null]);

        $admins = UserDepartmentAssignment::query()->current()->where('department_id', $this->department->id)->where('role', Role::DepartmentAdmin->value)->pluck('user_id')->all();
        $this->assertSame([$admin->id], $admins, 'One Department Admin per department.');
        $this->actingAs($previous)->get(route('department.allotments.index'))->assertRedirect(route('login'));
    }

    public function test_a_fresh_invitation_hands_the_department_account_to_the_new_person(): void
    {
        $this->invite();
        $first = $this->register($this->token());
        $epoch = (int) $first->session_epoch;

        $this->invite()->assertOk();
        $second = $this->register($this->token(), '9000000402', ['name' => 'Shri Anil Verma', 'date_of_birth' => '1982-07-01', 'gender' => 'M']);

        $this->assertSame($first->id, $second->id, 'The department keeps one account.');
        $this->assertSame(['Shri Anil Verma', '1982-07-01', 'M', '9000000402'], [$second->name, $second->date_of_birth?->toDateString(), $second->gender, $second->mobile]);
        $this->assertTrue(Hash::check('Dept@Admin2026', $second->password));
        $this->assertGreaterThan($epoch, (int) $second->session_epoch, 'The previous holder\'s sessions ended.');
        $this->assertNotNull($second->identity_verified_at);

        // The previous identity stays on record.
        $history = IdentityCorrection::query()->where('user_id', $second->id)->where('stage', IdentityCorrection::STAGE_HANDOVER)->pluck('old_value', 'field')->all();
        $this->assertSame(['name' => 'Smt. Kavita Sahu', 'date_of_birth' => '1979-02-14', 'gender' => 'F'], $history);
        $this->assertSame(2, IdentityVerification::query()->where('user_id', $second->id)->where('outcome', IdentityVerification::OUTCOME_VERIFIED)->count());
        $this->assertNotNull(IdentityVerification::query()->where('user_id', $second->id)->oldest('id')->first()?->revoked_at);
    }

    public function test_the_department_admin_maps_posts_only_for_their_own_department(): void
    {
        $admin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $foreign = DepartmentPost::query()->create(['department_id' => $this->other->id, 'name' => 'Professor', 'class_level' => 'I', 'status' => 'active']);
        $own = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Executive Engineer', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($admin)->get(route('department.posts.index'))->assertOk()->assertSee('Executive Engineer')->assertDontSee('Professor');
        $this->get(route('department.posts.show', $own))->assertOk();
        $this->get(route('department.posts.show', $foreign))->assertForbidden();
        $this->get(route('department.posts.edit', $foreign))->assertForbidden();
        $this->get(route('department.posts.create'))->assertOk()->assertSee($this->department->name)->assertDontSee($this->other->name);
        $this->post(route('department.posts.store'), ['department_id' => $this->other->id, 'name' => 'Lecturer', 'class_level' => 'I', 'status' => 'active'])->assertSessionHasErrors('department_id');
        $this->assertFalse(DepartmentPost::query()->where('name', 'Lecturer')->exists());

        // The Commission's Administrator keeps every department's.
        $this->actingAs($this->superAdmin)->get(route('admin.department-posts.index'))->assertOk()->assertSee('Executive Engineer')->assertSee('Professor');
        // An officer of the department does not.
        $officer = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $this->actingAs($officer)->get(route('department.posts.index'))->assertForbidden();
    }
}
