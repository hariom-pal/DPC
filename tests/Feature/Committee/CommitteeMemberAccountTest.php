<?php

declare(strict_types=1);

namespace Tests\Feature\Committee;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\CommitteeMasterSeeder;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * A DPC committee member's own portal login (business decision, 2026-11-18): made
 * or linked when the member is recorded, never by a conflicting e-mail and mobile;
 * its password set by the member through Forgot Password; a reset the
 * Administrator starts but never sees.
 */
class CommitteeMemberAccountTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New@Pass2026';

    private User $admin;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);
        Mail::fake();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
    }

    /** @param  list<array<string, mixed>>  $members */
    private function compose(array $members, ?DpcProposal $proposal = null): void
    {
        app(DpcCommitteeService::class)->compose($proposal ?? $this->proposal, $members, $this->ao);
    }

    private function member(string $name, string $email, string $mobile): array
    {
        return ['name' => $name, 'email' => $email, 'mobile' => $mobile, 'designation' => 'Joint Secretary', 'capacity' => 'member'];
    }

    private function seat(string $email): DpcCommitteeMember
    {
        return DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->serving()->where('email', $email)->firstOrFail();
    }

    // ------------------------------------------------------------- the account

    public function test_a_new_member_gets_a_login_with_no_role_and_no_password_issued(): void
    {
        $this->compose([$this->member('Smt. New Member', 'new.member@cg.gov.in', '9425000401')]);

        $user = User::query()->where('email', 'new.member@cg.gov.in')->sole();
        $this->assertSame($user->id, $this->seat('new.member@cg.gov.in')->user_id);
        $this->assertTrue($user->roles->isEmpty(), 'Only what the committee seat gives: no portal role.');
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'user.created_for_committee')->where('subject_id', $user->id)->count());
    }

    public function test_an_existing_account_is_linked_with_its_password_and_roles_untouched(): void
    {
        $existing = User::factory()->create(['email' => 'cgpsc.member@cg.gov.in', 'mobile' => '9425000402']);
        $existing->assignRole(Role::Member->value);
        $hash = $existing->password;
        $users = User::query()->count();

        $this->compose([$this->member('Typed Name', 'CGPSC.Member@cg.gov.in', '9425000402')]);

        $this->assertSame($existing->id, $this->seat('cgpsc.member@cg.gov.in')->user_id);
        $this->assertSame($users, User::query()->count(), 'No second account.');
        $this->assertSame($hash, $existing->fresh()->password, 'The password is never changed by linking.');
        $this->assertSame([Role::Member->value], $existing->fresh()->getRoleNames()->all());
    }

    public function test_one_person_sits_on_two_committees_with_one_account(): void
    {
        $this->compose([$this->member('Shri Two Seats', 'two.seats@cg.gov.in', '9425000403')]);
        $department = $this->proposal->department;
        $other = $this->raiseDpc($this->ao,
            DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Section Officer', 'class_level' => 'II', 'status' => 'active']),
            DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary II', 'class_level' => 'I', 'status' => 'active']),
        );
        $this->compose([$this->member('Shri Two Seats', 'two.seats@cg.gov.in', '9425000403')], $other);

        $this->assertSame(1, User::query()->where('email', 'two.seats@cg.gov.in')->count());
        $this->assertSame(2, DpcCommitteeMember::query()->where('email', 'two.seats@cg.gov.in')->distinct()->count('dpc_proposal_id'));
    }

    public function test_an_e_mail_and_mobile_of_two_different_accounts_is_refused_and_nothing_is_saved(): void
    {
        User::factory()->create(['email' => 'first@cg.gov.in', 'mobile' => '9425000404']);
        User::factory()->create(['email' => 'second@cg.gov.in', 'mobile' => '9425000405']);
        $seats = DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->count();

        try {
            $this->compose([$this->member('Mixed', 'first@cg.gov.in', '9425000405')]);
            $this->fail('A conflicting identity must be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('two different accounts', $e->getMessage());
        }

        $this->assertSame($seats, DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->count(), 'Nothing half-saved.');

        // Its own e-mail with somebody else's mobile is refused too.
        $this->expectException(WorkflowException::class);
        $this->compose([$this->member('Mixed', 'first@cg.gov.in', '9425000499')]);
    }

    public function test_the_form_requires_both_e_mail_and_mobile(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.committee.update', $this->proposal), ['members' => [
            ['name' => 'No Contact', 'designation' => 'Joint Secretary', 'capacity' => 'member'],
        ]])->assertSessionHasErrors(['members.0.email', 'members.0.mobile']);
    }

    // ---------------------------------------------------------- the password

    public function test_the_member_sets_the_password_through_forgot_password(): void
    {
        $this->compose([$this->member('Smt. Self Service', 'self.service@cg.gov.in', '9425000406')]);
        $user = User::query()->where('email', 'self.service@cg.gov.in')->sole();

        $this->post(route('password.forgot.send'), ['login' => 'self.service@cg.gov.in'])->assertRedirect(route('password.forgot.verify'));
        $this->post(route('password.forgot.update'), [
            'email_code' => '123456', 'mobile_code' => '123456', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $user = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_the_administrator_starts_a_reset_from_user_management_and_never_sees_a_password(): void
    {
        $this->compose([$this->member('Smt. Reset One', 'reset.one@cg.gov.in', '9425000407')]);
        $user = User::query()->where('email', 'reset.one@cg.gov.in')->sole();
        $hash = $user->password;

        $this->actingAs($this->admin)->from(route('admin.users.index'))->post(route('admin.users.reset-password', $user))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', __('dpc.committee.account.reset_started', ['name' => $user->name]));

        $this->assertSame($hash, $user->fresh()->password, 'No temporary password is issued.');
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'reset.one@cg.gov.in')->where('purpose', 'password_reset')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9425000407')->where('purpose', 'password_reset')->count());
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'password_reset.initiated_by_admin')->where('subject_id', $user->id)->count());

        // A staff account with a role keeps the existing reset (a temporary password).
        $staff = User::factory()->create();
        $staff->assignRole(Role::Member->value);
        $this->post(route('admin.users.reset-password', $staff))->assertOk()->assertSee('id="issued-password"', false);
    }

    public function test_the_administrator_starts_a_reset_from_the_committee_screen(): void
    {
        $this->compose([$this->member('Smt. Reset Two', 'reset.two@cg.gov.in', '9425000408')]);
        $seat = $this->seat('reset.two@cg.gov.in');

        $this->actingAs($this->admin)->get(route('admin.committee-members.index'))->assertOk()
            ->assertSee('data-member-reset="'.$seat->id.'"', false)->assertSee(__('dpc.committee.account.linked'))
            ->assertSee('reset.two@cg.gov.in')->assertSee('9425000408');

        $this->from(route('admin.committee-members.index'))
            ->post(route('admin.committee-members.reset-password', [$this->proposal, $seat]))
            ->assertSessionHas('status');
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'reset.two@cg.gov.in')->where('purpose', 'password_reset')->count());
    }

    public function test_nobody_but_the_administrator_starts_a_reset_or_creates_a_login(): void
    {
        $this->compose([$this->member('Smt. Guarded', 'guarded@cg.gov.in', '9425000409')]);
        $seat = $this->seat('guarded@cg.gov.in');

        $this->actingAs($this->ao)->get(route('admin.committee-members.index'))->assertForbidden();
        $this->actingAs($this->ao)->post(route('admin.committee-members.reset-password', [$this->proposal, $seat]))->assertForbidden();
        $this->actingAs($this->ao)->post(route('admin.committee-members.account', [$this->proposal, $seat]))->assertForbidden();
        $this->actingAs($this->ao)->post(route('admin.users.reset-password', $seat->user))->assertForbidden();
        $this->assertSame(0, OtpVerification::query()->where('purpose', 'password_reset')->count());
    }

    // -------------------------------------------------- a seat with no login yet

    public function test_a_seat_recorded_without_a_login_gets_one_or_links_safely(): void
    {
        $this->compose([$this->member('Smt. Legacy', 'legacy@cg.gov.in', '9425000410')]);
        $seat = $this->seat('legacy@cg.gov.in');
        // As a seat recorded before every member had a login.
        $seat->forceFill(['user_id' => null])->save();

        $this->actingAs($this->admin)->get(route('admin.committee-members.index', ['q' => 'legacy']))->assertOk()
            ->assertSee('data-member-create-login="'.$seat->id.'"', false)->assertSee(__('dpc.committee.account.none'));

        $this->from(route('admin.committee-members.index'))->post(route('admin.committee-members.account', [$this->proposal, $seat]))->assertSessionHas('status');
        $linked = $seat->fresh()->user_id;
        $this->assertSame(User::query()->where('email', 'legacy@cg.gov.in')->value('id'), $linked, 'The account its e-mail and mobile belong to.');
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'committee_member.account_linked')->count());

        // Once is enough.
        $this->post(route('admin.committee-members.account', [$this->proposal, $seat]))->assertSessionHasErrors('committee_account');
    }

    // ---------------------------------------------------------------- access

    public function test_a_member_reaches_only_their_own_dpc_and_records_only_their_own_decision(): void
    {
        $this->compose([$this->member('Smt. Seated', 'seated@cg.gov.in', '9425000411')]);
        $member = $this->seat('seated@cg.gov.in')->user;
        $member->forceFill(['must_change_password' => false, 'email_verified_at' => now(), 'mobile_verified_at' => now()])->save();
        $stranger = User::factory()->create();

        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertOk();
        $this->actingAs($stranger)->get(route('sitting.show', $this->proposal))->assertForbidden();
        $this->actingAs($stranger)->post(route('sitting.decision', $this->proposal), ['decision' => 'agreed'])->assertForbidden();
        $this->assertSame(0, DpcCommitteeDecision::query()->count());
    }
}
