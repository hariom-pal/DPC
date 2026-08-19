<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Report\Services\DpcReportService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reports — M11.
 *
 * **The assertion that matters most is the scope.** One screen serves every role,
 * so if the scope is wrong a department reads another department's promotion
 * figures. Every test below that names two departments is there for that reason.
 *
 * **The second is that nothing is recalculated.** Promotion figures come from the
 * calculation run each proceeding pinned at approval — never from a fresh
 * calculation, and never from a run that was superseded before approval. A report
 * that recalculated would be a second implementation of the frozen M5 engine and
 * would eventually disagree with the signed document it summarises.
 */
class DpcReportTest extends TestCase
{
    use RefreshDatabase;

    private Department $mine;

    private Department $theirs;

    private User $ao;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->mine = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->theirs = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);

        $this->ao = User::factory()->create(['department_id' => $this->mine->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->admin = User::factory()->create(['department_id' => null]);
        $this->admin->assignRole(Role::Admin->value);
    }

    // ------------------------------------------------------------------
    // Scope
    // ------------------------------------------------------------------

    public function test_a_department_sees_only_its_own_dpcs(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved);
        $this->proposal($this->theirs, DpcProposalStatus::Approved);
        $this->proposal($this->theirs, DpcProposalStatus::Approved);

        $reports = app(DpcReportService::class);

        $forwarded = collect($reports->pipeline($this->mine->id))
            ->firstWhere('stage', 'Forwarded to the Commission')['count'];

        $this->assertSame(1, $forwarded, 'a department must not count another department\'s DPCs');

        $allForwarded = collect($reports->pipeline(null))
            ->firstWhere('stage', 'Forwarded to the Commission')['count'];

        $this->assertSame(3, $allForwarded);
    }

    public function test_the_scope_is_derived_from_the_role(): void
    {
        $reports = app(DpcReportService::class);

        $this->assertSame($this->mine->id, $reports->scopeFor($this->ao));
        $this->assertNull($reports->scopeFor($this->admin));
    }

    /**
     * A department comparing itself against others is a disclosure this report has
     * no reason to make, so the per-department table is simply absent for them.
     */
    public function test_the_per_department_table_is_withheld_from_a_department(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved);
        $this->proposal($this->theirs, DpcProposalStatus::Approved);

        $reports = app(DpcReportService::class);

        $this->assertSame([], $reports->byDepartment($this->mine->id));
        $this->assertCount(2, $reports->byDepartment(null));

        $this->actingAs($this->ao)->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('Higher Education');

        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Higher Education');
    }

    /** A registered department with no DPC would pad the table with zeroes. */
    public function test_a_department_with_no_dpc_is_omitted_from_the_table(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved);

        $rows = app(DpcReportService::class)->byDepartment(null);

        $this->assertCount(1, $rows);
        $this->assertSame('School Education', $rows[0]['department']);
    }

    // ------------------------------------------------------------------
    // The pipeline
    // ------------------------------------------------------------------

    public function test_the_pipeline_counts_each_stage(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Draft);
        $this->proposal($this->mine, DpcProposalStatus::Submitted);
        $this->proposal($this->mine, DpcProposalStatus::Returned);
        $approved = $this->proposal($this->mine, DpcProposalStatus::Approved);

        $this->allot($approved);
        $this->proceeding($approved, DpcProceedingStatus::InProceeding);

        $pipeline = collect(app(DpcReportService::class)->pipeline($this->mine->id))
            ->pluck('count', 'stage');

        $this->assertSame(1, $pipeline['Drafted by the department']);
        $this->assertSame(1, $pipeline['Awaiting departmental approval']);
        $this->assertSame(1, $pipeline['Returned for correction']);
        $this->assertSame(1, $pipeline['Forwarded to the Commission']);
        $this->assertSame(1, $pipeline['Allotted to a Member']);
        $this->assertSame(1, $pipeline['Sitting opened']);
        $this->assertSame(0, $pipeline['Result approved']);
    }

    /**
     * "Allotted" means the allotment now in force — M6's own definition. A
     * re-allotted DPC must count once, not twice.
     */
    public function test_a_superseded_allotment_does_not_count_twice(): void
    {
        $proposal = $this->proposal($this->mine, DpcProposalStatus::Approved);

        $first = $this->allot($proposal);
        $second = $this->allot($proposal);
        $first->forceFill(['superseded_by_id' => $second->id])->save();

        $count = collect(app(DpcReportService::class)->pipeline($this->mine->id))
            ->firstWhere('stage', 'Allotted to a Member')['count'];

        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------------
    // Outcomes — from stored results only
    // ------------------------------------------------------------------

    public function test_outcomes_are_counted_from_the_run_the_proceeding_pinned_at_approval(): void
    {
        $proposal = $this->proposal($this->mine, DpcProposalStatus::Approved);

        // A working run, superseded before approval, and the run actually
        // approved. Only the second may be counted.
        $draftRun = $this->calculationRun($proposal, mainList: 5, waitingList: 5);
        $finalRun = $this->calculationRun($proposal, mainList: 2, waitingList: 1);

        $this->proceeding($proposal, DpcProceedingStatus::ResultApproved, $finalRun);

        $outcomes = app(DpcReportService::class)->outcomes($this->mine->id);

        $this->assertSame(2, $outcomes['promoted'], 'the superseded run must not be counted');
        $this->assertSame(1, $outcomes['waiting']);

        unset($draftRun);
    }

    public function test_a_proceeding_without_an_approved_result_contributes_nothing(): void
    {
        $proposal = $this->proposal($this->mine, DpcProposalStatus::Approved);
        $run = $this->calculationRun($proposal, mainList: 3, waitingList: 2);

        // Open, not approved: the figures are provisional and must not be reported
        // as outcomes.
        $this->proceeding($proposal, DpcProceedingStatus::InProceeding, $run);

        $this->assertSame(
            ['promoted' => 0, 'waiting' => 0, 'held' => 0, 'not_eligible' => 0],
            app(DpcReportService::class)->outcomes($this->mine->id),
        );
    }

    public function test_outcomes_are_scoped_to_the_department(): void
    {
        $ours = $this->proposal($this->mine, DpcProposalStatus::Approved);
        $this->proceeding($ours, DpcProceedingStatus::ResultApproved, $this->calculationRun($ours, 1, 0));

        $theirs = $this->proposal($this->theirs, DpcProposalStatus::Approved);
        $this->proceeding($theirs, DpcProceedingStatus::ResultApproved, $this->calculationRun($theirs, 4, 0));

        $this->assertSame(1, app(DpcReportService::class)->outcomes($this->mine->id)['promoted']);
        $this->assertSame(5, app(DpcReportService::class)->outcomes(null)['promoted']);
    }

    // ------------------------------------------------------------------
    // By year
    // ------------------------------------------------------------------

    /**
     * Grouped on the date the department filed the proposal for, not on
     * `created_at` — a backfilled proposal would otherwise land in the wrong year.
     */
    public function test_dpcs_are_grouped_by_the_proposal_date_not_the_row_date(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved, '2024-04-01');
        $this->proposal($this->mine, DpcProposalStatus::Approved, '2026-06-25');
        $this->proposal($this->mine, DpcProposalStatus::Approved, '2026-07-01');

        $byYear = app(DpcReportService::class)->byYear($this->mine->id);

        // Newest first.
        $this->assertSame([['year' => 2026, 'count' => 2], ['year' => 2024, 'count' => 1]], $byYear);
    }

    // ------------------------------------------------------------------
    // The screen
    // ------------------------------------------------------------------

    public function test_the_screen_renders_for_every_role_and_says_whose_figures_they_are(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved);

        $this->actingAs($this->ao)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('School Education')
            ->assertSee("your department's own DPCs", escape: false);

        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Every department, across the Commission');
    }

    public function test_the_screen_requires_authentication(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_the_export_streams_the_pipeline_scoped_to_the_caller(): void
    {
        $this->proposal($this->mine, DpcProposalStatus::Approved);
        $this->proposal($this->theirs, DpcProposalStatus::Approved);
        $this->proposal($this->theirs, DpcProposalStatus::Approved);

        $mine = $this->actingAs($this->ao)->get(route('reports.export'));
        $mine->assertOk();
        $mine->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('"Forwarded to the Commission",S25,1', $mine->streamedContent());

        $all = $this->actingAs($this->admin)->get(route('reports.export'));

        $this->assertStringContainsString('"Forwarded to the Commission",S25,3', $all->streamedContent());
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function proposal(
        Department $department,
        DpcProposalStatus $status,
        string $date = '2026-06-25',
    ): DpcProposal {
        $type = DpcType::query()->where('code', 'fresh')->firstOrFail();

        return DpcProposal::query()->create([
            'department_id' => $department->id,
            'dpc_type_id' => $type->id,
            'post_to_cadre_id' => $this->cadre($department, 'Deputy Director')->id,
            'post_from_cadre_id' => $this->cadre($department, 'Under Secretary')->id,
            'proposal_date' => $date,
            'status' => $status->value,
        ]);
    }

    private function cadre(Department $department, string $name): Cadre
    {
        return Cadre::query()->firstOrCreate(
            ['department_id' => $department->id, 'name' => $name],
            ['class_level' => 'I', 'status' => MasterStatus::Active->value],
        );
    }

    private function allot(DpcProposal $proposal): DpcAllotment
    {
        $member = User::factory()->create(['department_id' => null]);

        return DpcAllotment::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'allotted_to' => $member->id,
            'allotted_at' => now(),
        ]);
    }

    private function proceeding(
        DpcProposal $proposal,
        DpcProceedingStatus $status,
        ?DpcCalculationRun $run = null,
    ): DpcProceeding {
        return DpcProceeding::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'status' => $status->value,
            'conducted_by_name' => 'Member One',
            'proposal_version' => str_repeat('a', 64),
            'opened_at' => now()->subDay(),
            'final_calculation_run_id' => $run?->id,
            'approved_at' => $status === DpcProceedingStatus::ResultApproved ? now() : null,
        ]);
    }

    /**
     * A calculation run with stored results — the shape the report reads.
     *
     * Not named `run()`: `PHPUnit\Framework\TestCase::run()` is final, and
     * overriding it is a fatal error rather than a failing test.
     */
    private function calculationRun(DpcProposal $proposal, int $mainList, int $waitingList): DpcCalculationRun
    {
        /** @var DpcCalculationRun $run */
        $run = DpcCalculationRun::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'rule_version' => '2026.1',
            'configuration_snapshot' => [],
            'proposal_version' => str_repeat('b', 64),
            'application_version' => '0.0.0-test',
            'git_commit_hash' => str_repeat('c', 40),
            'executed_at' => now(),
            'zone' => [],
            'officers_considered' => $mainList + $waitingList,
            'eligible_count' => $mainList + $waitingList,
            'main_list_count' => $mainList,
            'waiting_list_count' => $waitingList,
        ]);

        $sn = 1;

        foreach (['main' => $mainList, 'waiting' => $waitingList] as $list => $count) {
            for ($i = 0; $i < $count; $i++) {
                DpcCalculationResult::query()->create([
                    'dpc_calculation_run_id' => $run->id,
                    'sn' => $sn++,
                    'officer_name' => 'Officer '.Str::random(4),
                    'reservation_category_code' => 'UR',
                    'score_breakdown' => [],
                    'verdict' => [],
                    'super_seat' => [],
                    'is_eligible' => true,
                    'remark' => $list === 'main' ? 'UR' : 'UR_W',
                    'list_type' => $list,
                ]);
            }
        }

        return $run;
    }
}
