<?php

declare(strict_types=1);

namespace Tests\Feature\Candidate;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\UserManagement\Services\PromotionCandidateProvisioner;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The Promotion Candidate (change item 17).
 *
 * Two halves, and the second is the one that matters most: every officer on a
 * Seniority List gets a login, and that login sees **only** their own DPC, their
 * own status, the timeline, their notifications and the published result. The
 * register forbids the rest by name — other candidates, the committee, the
 * evaluation, internal remarks, other officers' marks — so most of the assertions
 * below are about what is *not* reachable.
 */
class PromotionCandidateTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Manual entry is off by default since 2026-09-07 — the two grids are
         | upload-only — and the routes are gated on the same setting the screens
         | are. Switched on for this class, which exercises those routes directly.
         |
         | Kept as tests rather than deleted: the instruction was *"hide it, in case
         | we want it back"*, and a feature nobody tests is not one that can come
         | back.
         */
        config([
            'dpc.manual_entry.working_officers' => true,
            'dpc.manual_entry.seniority_list' => true,
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();

        $from = $this->departmentPost('Under Secretary', 5);
        $to = $this->departmentPost('Deputy Secretary');

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
    }

    private function departmentPost(string $name, ?int $duration = null): DepartmentPost
    {
        return DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => $name,
            'class_level' => 'I',
            'promotion_duration_years' => $duration, 'retirement_age' => 62,
            'status' => MasterStatus::Active->value,
        ]);
    }

    private function postFor(Department $department, string $name, ?int $duration = null): DepartmentPost
    {
        return DepartmentPost::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'class_level' => 'I',
            'promotion_duration_years' => $duration, 'retirement_age' => 62,
            'status' => MasterStatus::Active->value,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function saveSeniorityList(array $entries): void
    {
        // `assertSessionHasNoErrors` rather than only `assertRedirect`: a rejected
        // save is also a redirect, and a fixture that silently saved nothing would
        // make every assertion below fail for the wrong reason.
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => $entries],
        )->assertSessionHasNoErrors()->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(int $sn, string $name, ?string $employeeNumber): array
    {
        return [
            'seniority_number' => $sn,
            'name' => $name,
            'employee_number' => $employeeNumber,
            'dob' => '1975-04-01',
            'date_of_joining_service' => '1999-07-01',
            'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
            'promotion_from_post' => 'Under Secretary',
            'promotion_to_post' => 'Deputy Secretary',
        ];
    }

    // ------------------------------------------------------------------
    // Provisioning
    // ------------------------------------------------------------------

    public function test_saving_a_seniority_list_creates_a_login_for_every_officer_on_it(): void
    {
        $this->saveSeniorityList([
            $this->entry(1, 'Shri A. Officer', 'EMP-001'),
            $this->entry(2, 'Smt. B. Officer', 'EMP-002'),
        ]);

        foreach (['EMP-001', 'EMP-002'] as $employeeNumber) {
            $account = User::query()->where('employee_number', $employeeNumber)->sole();

            $this->assertTrue($account->hasRole(Role::PromotionCandidate->value));
            $this->assertTrue($account->must_change_password);
            $this->assertSame($this->department->id, $account->department_id);
        }
    }

    public function test_an_existing_login_is_reused_and_never_duplicated(): void
    {
        $this->saveSeniorityList([$this->entry(1, 'Shri A. Officer', 'EMP-001')]);

        $first = User::query()->where('employee_number', 'EMP-001')->sole();

        // Re-uploading the chart, and then a second DPC naming the same officer.
        $this->saveSeniorityList([$this->entry(1, 'Shri A. Officer', 'EMP-001')]);

        $this->assertSame(1, User::query()->where('employee_number', 'EMP-001')->count());
        $this->assertSame($first->id, User::query()->where('employee_number', 'EMP-001')->sole()->id);
    }

    public function test_an_officer_transferred_between_departments_keeps_one_account(): void
    {
        $this->saveSeniorityList([$this->entry(1, 'Hariom Pal', 'EMP-777')]);

        $forest = Department::query()->create(['code' => 'FOR', 'name' => 'Forest', 'status' => 'active']);
        $forestAo = User::factory()->assignedTo($forest, Role::AuthorizedOfficer)->create();

        $from = DepartmentPost::query()->create([
            'department_id' => $forest->id, 'name' => 'Ranger', 'class_level' => 'II',
            'promotion_duration_years' => 5, 'retirement_age' => 62, 'status' => MasterStatus::Active->value,
        ]);
        $to = DepartmentPost::query()->create([
            'department_id' => $forest->id, 'name' => 'Deputy Conservator', 'class_level' => 'I',
            'status' => MasterStatus::Active->value,
        ]);

        $forestProposal = $this->raiseDpc($forestAo, $from, $to);

        $this->actingAs($forestAo)->put(
            route('dpc-proposals.seniority-list.update', $forestProposal),
            ['seniority_list_as_on' => $forestProposal->seniorityReferenceDate()->toDateString(), 'entries' => [$this->entry(1, 'Hariom Pal', 'EMP-777')]],
        )->assertSessionHasNoErrors()->assertRedirect();

        // The register's instruction in both item 2 and item 17: never create
        // duplicate users.
        $this->assertSame(1, User::query()->where('employee_number', 'EMP-777')->count());
    }

    /**
     * Two departments, one employee number, two different officers.
     *
     * **The defect this pins was live and silent.** An employee number is issued by
     * a department, so two departments can each hold an officer numbered 1024 with
     * neither being wrong. Matching on the number alone did two things: it
     * **re-posted** the officer who held it — retiring their real posting, so an
     * Under Secretary in Higher Education was transferred into Forest by Forest's
     * data entry — and it let that one account see **both** officers' Seniority List
     * entries, which is the disclosure item 17 exists to prevent.
     *
     * Neither reading of the situation is assumed. Nothing is created, nothing is
     * moved, and the mismatch is reported for the Administrator (BD-27).
     */
    public function test_two_departments_may_each_number_an_officer_1024(): void
    {
        $this->saveSeniorityList([$this->entry(1, 'Shri Anil Verma', '1024')]);

        $officer = User::query()->where('employee_number', '1024')->sole();
        $this->assertSame($this->department->id, $officer->department?->id);

        // A second department lists a different person, also numbered 1024.
        $forest = Department::query()->create(['code' => 'FOR', 'name' => 'Forest', 'status' => 'active']);
        $forestAo = User::factory()->assignedTo($forest, Role::AuthorizedOfficer)->create();
        $forestProposal = $this->raiseDpc(
            $forestAo,
            $this->postFor($forest, 'Ranger', 5),
            $this->postFor($forest, 'Deputy Conservator'),
        );

        $this->actingAs($forestAo)->put(
            route('dpc-proposals.seniority-list.update', $forestProposal),
            ['seniority_list_as_on' => $forestProposal->seniorityReferenceDate()->toDateString(), 'entries' => [[
                'seniority_number' => 1,
                'name' => 'Smt. Kavita Rao',
                'employee_number' => '1024',
                'dob' => '1975-04-01',
                'date_of_joining_service' => '1999-07-01',
                'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
                'promotion_from_post' => 'Ranger',
                'promotion_to_post' => 'Deputy Conservator',
            ]]],
        )->assertSessionHasNoErrors()->assertRedirect();

        $officer->refresh();
        $officer->unsetRelation('activeAssignment');

        // Still one account, still in the department they actually work in.
        $this->assertSame(1, User::query()->where('employee_number', '1024')->count());
        $this->assertSame(
            $this->department->id,
            $officer->department?->id,
            "The officer must not be transferred by another department's data entry.",
        );

        // And they see their own entry only — not the other officer's name.
        $entries = PromotionCandidateProvisioner::entriesFor($officer)->get();
        $this->assertSame(1, $entries->count());
        $this->assertSame('Shri Anil Verma', $entries->sole()->name);
    }

    public function test_the_clash_is_reported_to_the_officer_rather_than_resolved(): void
    {
        $this->saveSeniorityList([$this->entry(1, 'Shri Anil Verma', '1024')]);

        $forest = Department::query()->create(['code' => 'FOR', 'name' => 'Forest', 'status' => 'active']);
        $forestAo = User::factory()->assignedTo($forest, Role::AuthorizedOfficer)->create();
        $forestProposal = $this->raiseDpc(
            $forestAo,
            $this->postFor($forest, 'Ranger', 5),
            $this->postFor($forest, 'Deputy Conservator'),
        );

        $this->actingAs($forestAo)->put(
            route('dpc-proposals.seniority-list.update', $forestProposal),
            ['seniority_list_as_on' => $forestProposal->seniorityReferenceDate()->toDateString(), 'entries' => [[
                'seniority_number' => 1,
                'name' => 'Smt. Kavita Rao',
                'employee_number' => '1024',
                'dob' => '1975-04-01',
                'date_of_joining_service' => '1999-07-01',
                'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
                'promotion_from_post' => 'Ranger',
                'promotion_to_post' => 'Deputy Conservator',
            ]]],
        )->assertSessionHas('candidateAccounts', fn (array $accounts): bool => $accounts['conflicts'] === ['1024']);
    }

    public function test_no_password_is_shown_or_handed_over(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['seniority_list_as_on' => $this->proposal->seniorityReferenceDate()->toDateString(), 'entries' => [$this->entry(1, 'Shri A. Officer', 'EMP-001')]],
        )->assertSessionHas('candidateAccounts', fn (array $accounts): bool => array_keys($accounts['created']) === ['EMP-001']);

        $password = session('candidateAccounts')['created']['EMP-001'];

        // The account is made (the candidate's eSign signer on the Candidate Portal);
        // its password is never shown — a candidate does not sign in with one.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee(__('dpc.seniority_list.accounts.created_count', ['count' => 1]))
            ->assertDontSee($password);
    }

    public function test_an_officer_with_no_employee_number_is_reported_rather_than_guessed_at(): void
    {
        // Since 2026-10-17 the list's screens require an Employee ID, so a row
        // without one reaches provisioning only from a list saved before then —
        // written here through the service, as such a list was.
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            $this->entry(1, 'Shri A. Officer', 'EMP-001'),
            $this->entry(2, 'Shri No Number', null),
        ], $this->ao);

        $this->assertSame(1, User::query()->whereNotNull('employee_number')->count());

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.candidate_accounts_provisioned',
            'subject_id' => $this->proposal->id,
        ]);
    }

    public function test_an_authorized_officer_on_their_own_seniority_list_is_not_demoted(): void
    {
        $this->ao->forceFill(['employee_number' => 'EMP-AO'])->save();

        $this->saveSeniorityList([$this->entry(1, $this->ao->name, 'EMP-AO')]);

        $this->ao->refresh()->unsetRelation('roles');

        $this->assertTrue(
            $this->ao->hasRole(Role::AuthorizedOfficer->value),
            'Being considered for promotion must not cost an officer the role they hold.',
        );
        $this->assertFalse($this->ao->hasRole(Role::PromotionCandidate->value));
    }

    public function test_a_candidate_never_signs_in_here_with_a_password(): void
    {
        // One login for a candidate since 2026-11-20: the Candidate Portal (mobile, OTP,
        // eSign). The account stays — it is their eSign signer there.
        $candidate = $this->provisionedCandidate('EMP-001');
        $candidate->forceFill(['password' => bcrypt('known-password'), 'must_change_password' => false])->save();
        auth()->logout();

        $this->post(route('login.store'), ['login' => 'EMP-001', 'password' => 'known-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => __('authui.login.candidate_use_portal')]);
        $this->assertGuest();
    }

    private function provisionedCandidate(string $employeeNumber): User
    {
        $this->saveSeniorityList([$this->entry(1, 'Shri A. Officer', $employeeNumber)]);

        $candidate = User::query()->where('employee_number', $employeeNumber)->sole();

        /*
         | Past the first-login password change.
         |
         | A provisioned account carries `must_change_password`, and
         | `EnsurePasswordChanged` redirects every application screen until it is
         | cleared — which is right, and is what a real candidate does on first
         | login. Leaving it set here would make every assertion below fail on a
         | 302 that says nothing about what the candidate may see.
         */
        $candidate->forceFill(['must_change_password' => false])->save();

        return $candidate->refresh();
    }
}
