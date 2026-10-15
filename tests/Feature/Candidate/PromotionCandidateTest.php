<?php

declare(strict_types=1);

namespace Tests\Feature\Candidate;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Shared\Services\ActivityTimelineService;
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
            'promotion_duration_years' => $duration,
            'status' => MasterStatus::Active->value,
        ]);
    }

    private function postFor(Department $department, string $name, ?int $duration = null): DepartmentPost
    {
        return DepartmentPost::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'class_level' => 'I',
            'promotion_duration_years' => $duration,
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
            ['entries' => $entries],
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
            'promotion_duration_years' => 5, 'status' => MasterStatus::Active->value,
        ]);
        $to = DepartmentPost::query()->create([
            'department_id' => $forest->id, 'name' => 'Deputy Conservator', 'class_level' => 'I',
            'status' => MasterStatus::Active->value,
        ]);

        $forestProposal = $this->raiseDpc($forestAo, $from, $to);

        $this->actingAs($forestAo)->put(
            route('dpc-proposals.seniority-list.update', $forestProposal),
            ['entries' => [$this->entry(1, 'Hariom Pal', 'EMP-777')]],
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
            ['entries' => [[
                'seniority_number' => 1,
                'name' => 'Smt. Kavita Rao',
                'employee_number' => '1024',
                'dob' => '1975-04-01',
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
            ['entries' => [[
                'seniority_number' => 1,
                'name' => 'Smt. Kavita Rao',
                'employee_number' => '1024',
                'dob' => '1975-04-01',
                'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
                'promotion_from_post' => 'Ranger',
                'promotion_to_post' => 'Deputy Conservator',
            ]]],
        )->assertSessionHas('candidateAccounts', fn (array $accounts): bool => $accounts['conflicts'] === ['1024']);
    }

    /**
     * The temporary passwords reach the screen.
     *
     * **Nowhere else can produce them.** A password is generated, hashed and
     * forgotten, so a caller that drops the provisioning result creates accounts
     * nobody can sign in to — which is what happened between item 17 shipping and
     * 2026-08-25, and made the candidate login unusable in production while every
     * test passed.
     */
    public function test_the_temporary_passwords_are_shown_once_to_the_officer(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            ['entries' => [$this->entry(1, 'Shri A. Officer', 'EMP-001')]],
        )->assertSessionHas('candidateAccounts', function (array $accounts): bool {
            return array_keys($accounts['created']) === ['EMP-001']
                && $accounts['created']['EMP-001'] !== '';
        });

        $password = session('candidateAccounts')['created']['EMP-001'];

        // And the panel actually renders them, once.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('EMP-001')
            ->assertSee($password);

        // A refresh does not: the value was flashed, not stored.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertDontSee($password);
    }

    public function test_an_officer_with_no_employee_number_is_reported_rather_than_guessed_at(): void
    {
        $this->saveSeniorityList([
            $this->entry(1, 'Shri A. Officer', 'EMP-001'),
            $this->entry(2, 'Shri No Number', null),
        ]);

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

    public function test_a_candidate_can_sign_in_with_their_employee_number(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');

        // The account has no email and no mobile, which is why the employee number
        // is a login identifier at all.
        $this->assertNull($candidate->email);
        $this->assertNull($candidate->mobile);

        // The password is only ever returned once, at creation, so the test resets
        // it rather than trying to recover it.
        $candidate->forceFill(['password' => bcrypt('known-password'), 'must_change_password' => false])->save();

        $this->post(route('login.store'), ['login' => 'EMP-001', 'password' => 'known-password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($candidate->fresh());
    }

    // ------------------------------------------------------------------
    // What the candidate may see
    // ------------------------------------------------------------------

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

    private function forwardProposal(): void
    {
        $this->proposal->forceFill(['forwarded_at' => now()])->save();
    }

    public function test_a_candidate_sees_their_own_dpc_once_it_has_been_filed(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        $this->actingAs($candidate)->get(route('my-dpc.index'))
            ->assertOk()
            ->assertSee($this->proposal->dpc_code)
            ->assertSee('Deputy Secretary');
    }

    public function test_a_draft_proposal_is_not_visible_to_the_candidate(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');

        // Not forwarded: still the department's working paper.
        $this->actingAs($candidate)->get(route('my-dpc.index'))
            ->assertOk()
            ->assertDontSee($this->proposal->dpc_code);

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))->assertNotFound();
    }

    public function test_a_candidate_sees_their_own_entry_and_their_own_status(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))
            ->assertOk()
            ->assertSee('Shri A. Officer')
            ->assertSee('EMP-001');
    }

    public function test_a_candidate_never_sees_another_candidate(): void
    {
        $this->saveSeniorityList([
            $this->entry(1, 'Shri A. Officer', 'EMP-001'),
            $this->entry(2, 'Smt. B. Officer', 'EMP-002'),
        ]);
        $this->forwardProposal();

        $candidate = User::query()->where('employee_number', 'EMP-001')->sole();
        $candidate->forceFill(['must_change_password' => false])->save();

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))
            ->assertOk()
            ->assertSee('Shri A. Officer')
            ->assertDontSee('Smt. B. Officer')
            ->assertDontSee('EMP-002');
    }

    public function test_a_candidate_cannot_see_another_officers_dpc(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        // A second exercise the candidate is not named on.
        $other = $this->raiseDpc(
            $this->ao,
            $this->departmentPost('Section Officer', 4),
            $this->departmentPost('Joint Secretary'),
        );
        $other->forceFill(['forwarded_at' => now()])->save();

        // A 404, not a 403: a 403 would confirm the proposal exists.
        $this->actingAs($candidate)->get(route('my-dpc.show', $other->uuid))->assertNotFound();
    }

    /**
     * The forbidden list, checked as a list.
     */
    public function test_a_candidate_is_refused_every_departmental_and_commission_screen(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        $screens = [
            route('dpc-proposals.index'),
            route('dpc-proposals.show', $this->proposal),
            route('dpc-proposals.committee', $this->proposal),
            route('dpc-proposals.seniority-list', $this->proposal),
            route('dpc-proposals.evaluation-chart', $this->proposal),
            route('dpc-proposals.working-officers', $this->proposal),
            route('dpc-proposals.calculations.index', $this->proposal),
            route('pre-dpc.index'),
            route('cgpsc.allotments.index'),
            route('admin.dashboard'),

            // The Commission Members master was withdrawn on 2026-08-26 (item 5).
            // The CGPSC Chairman screen replaced it as the Administrator's
            // committee-facing master, so that is the one to keep out of reach.
            route('admin.cgpsc-chairman.index'),
        ];

        foreach ($screens as $screen) {
            $response = $this->actingAs($candidate)->get($screen);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                "A Promotion Candidate reached {$screen} with a ".$response->getStatusCode().'.',
            );
        }
    }

    public function test_the_result_is_not_stated_before_it_is_published(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))
            ->assertOk()
            ->assertSee('has not been declared yet');
    }

    public function test_the_timeline_shows_progress_without_internal_remarks(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $this->forwardProposal();

        // A return remark is the Administrative Head's instruction to the officer;
        // it is internal and routinely names other officers' data.
        app(ActivityTimelineService::class)->record(
            'dpc_proposal.return',
            $this->proposal,
            'Correct the CR entries for Smt. B. Officer',
        );

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))
            ->assertOk()
            ->assertSee('returned to the Authorized Officer for correction')
            ->assertDontSee('Smt. B. Officer');
    }

    /**
     * The other two forbidden categories, pinned the same way.
     *
     * **Why this exists when the controller cannot leak them.** `show()` loads the
     * proposal, the candidate's own entry, a whitelisted timeline and the published
     * result — it never touches `dpc_committee_members` or a calculation result, so
     * on today's code this test cannot fail. That is precisely the argument for
     * having it: the register forbids five things by name, three of them were
     * already pinned by a rendered assertion, and the two that were not were the
     * two nobody would notice becoming visible. A `with()` added for a status badge,
     * or a shared partial pulled into this view, is all it would take.
     *
     * The committee and the marks are created here rather than mocked, so the page
     * is rendered against a DPC that genuinely holds both.
     */
    public function test_a_candidate_sees_neither_the_committee_nor_anybody_s_marks(): void
    {
        $this->saveSeniorityList([
            $this->entry(1, 'Shri A. Officer', 'EMP-001'),
            $this->entry(2, 'Smt. B. Officer', 'EMP-002'),
        ]);
        $this->forwardProposal();

        DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'name' => 'Hon. Committee Chairman',
            'designation' => 'Principal Secretary',
            'capacity' => CommitteeCapacity::CgpscChairman->value,
            'sort_order' => 1,

            // Serving, so `committeeMembers` sees it — the point of the test is
            // that a candidate is not shown the committee, and a row the relation
            // filtered out would make the assertion pass for the wrong reason.
            'active_flag' => 1,
        ]);

        // The provenance columns are all NOT NULL on purpose — a stored
        // calculation must be explainable — so a fixture has to supply them.
        $run = DpcCalculationRun::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'rule_version' => 'test',
            'configuration_snapshot' => [],
            'proposal_version' => str_repeat('0', 64),
            'application_version' => 'test',
            'git_commit_hash' => 'test',
            'zone' => [],
            'executed_at' => now(),
        ]);

        foreach ([
            ['sn' => 1, 'name' => 'Shri A. Officer', 'employee' => 'EMP-001', 'total' => '71.50'],
            ['sn' => 2, 'name' => 'Smt. B. Officer', 'employee' => 'EMP-002', 'total' => '83.25'],
        ] as $result) {
            DpcCalculationResult::query()->create([
                'dpc_calculation_run_id' => $run->id,
                'sn' => $result['sn'],
                'officer_name' => $result['name'],
                'employee_number' => $result['employee'],
                'reservation_category_code' => 'UR',
                'score_breakdown' => ['total' => $result['total']],
                'verdict' => ['eligible' => true],
                'super_seat' => ['applies' => false],
                'remark' => 'RECOMMENDED',
                'list_type' => ListType::Main->value,
            ]);
        }

        $candidate = User::query()->where('employee_number', 'EMP-001')->sole();
        $candidate->forceFill(['must_change_password' => false])->save();

        $this->actingAs($candidate)->get(route('my-dpc.show', $this->proposal->uuid))
            ->assertOk()
            // The committee, in either of the two ways it could surface.
            ->assertDontSee('Hon. Committee Chairman')
            ->assertDontSee('Principal Secretary')
            // Any officer's score, including the candidate's own — item 17 lists
            // other officers' marks as forbidden and is silent on a candidate's
            // own, so the portal shows neither (BD-25).
            ->assertDontSee('83.25')
            ->assertDontSee('71.50');
    }

    // ------------------------------------------------------------------
    // Reissuing a lost password (suggestion 4)
    // ------------------------------------------------------------------

    public function test_the_department_reissues_a_candidate_s_password_and_sees_it_once(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');
        $before = $candidate->password;

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.candidates.reissue-password', $this->proposal),
            ['employee_number' => 'EMP-001'],
        )->assertRedirect()->assertSessionHas('candidateAccounts', function (array $accounts): bool {
            return array_keys($accounts['created']) === ['EMP-001'];
        });

        $candidate->refresh();
        $this->assertNotSame($before, $candidate->password, 'The stored hash must change.');
        $this->assertTrue($candidate->must_change_password, 'A reissued password is temporary.');

        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.candidate_password_reissued',
            'subject_id' => $this->proposal->id,
        ]);
    }

    /**
     * The guard is the Seniority List, not the role.
     *
     * Checking the role instead would let any department reset any candidate in the
     * portal — the officers of one department are not another department's to touch.
     */
    public function test_a_department_cannot_reissue_for_an_officer_it_has_not_named(): void
    {
        $this->provisionedCandidate('EMP-001');

        // An account that exists, but on nobody's list here.
        $stranger = User::factory()->create(['employee_number' => 'EMP-999']);
        $stranger->assignRole(Role::PromotionCandidate->value);
        $before = $stranger->password;

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.candidates.reissue-password', $this->proposal),
            ['employee_number' => 'EMP-999'],
        )->assertSessionHasErrors('employee_number');

        $this->assertSame($before, $stranger->fresh()?->password);
    }

    public function test_a_candidate_cannot_reissue_anybody_s_password(): void
    {
        $candidate = $this->provisionedCandidate('EMP-001');

        $this->actingAs($candidate)->post(
            route('dpc-proposals.candidates.reissue-password', $this->proposal),
            ['employee_number' => 'EMP-001'],
        )->assertForbidden();
    }

    public function test_reissuing_is_refused_once_the_proposal_has_left_the_department(): void
    {
        $this->provisionedCandidate('EMP-001');
        $this->proposal->forceFill(['status' => 'submitted'])->save();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.candidates.reissue-password', $this->proposal),
            ['employee_number' => 'EMP-001'],
        )->assertForbidden();
    }

    public function test_the_candidate_scoping_is_empty_for_an_account_with_no_employee_number(): void
    {
        $stranger = User::factory()->assignedTo($this->department, Role::PromotionCandidate)->create();
        $stranger->forceFill(['must_change_password' => false])->save();

        $this->assertSame(0, PromotionCandidateProvisioner::entriesFor($stranger)->count());

        $this->actingAs($stranger)->get(route('my-dpc.index'))
            ->assertOk()
            ->assertSee('not currently named in any DPC');
    }
}
