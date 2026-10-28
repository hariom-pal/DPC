<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Shared\Services\IdentifierSequenceService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\AllotsProposals;
use Tests\Concerns\RecordsSanctionedStrength;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\Concerns\UsesPptPromotionRules;

/**
 * A department, a Commission, an approved proposal and an open sitting —
 * the setup every M7 Phase 4 test starts from.
 *
 * Extracted rather than copied a third time: three test classes building the
 * same eight-step arrangement by hand is how two of them quietly stop testing
 * the same thing.
 */
trait BuildsProceedings
{
    use AllotsProposals;
    use RecordsSanctionedStrength;
    use RefreshDatabase;
    use SeatsDpcCommittees;
    use SeedsEvaluationCharts;
    use UsesPptPromotionRules;

    protected Department $department;

    protected User $chairman;

    protected User $memberOne;

    protected User $memberTwo;

    protected User $authorizedOfficer;

    protected User $administrativeHead;

    protected DpcProposal $proposal;

    protected DpcProceeding $proceeding;

    protected function bootProceeding(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->chairman = User::factory()->create(['name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $this->authorizedOfficer = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create(['name' => 'Authorized Officer']);

        // Added for TD-23: driving a proposal through the real submit gate
        // needs the Administrative Head who approves it. Creating the user is
        // additive — no existing test observes the user count.
        $this->administrativeHead = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create(['name' => 'Administrative Head']);

        // Class-I -> Class-I, the PPT's own worked case.
        $postFrom = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'promotion_to_post_id' => $postTo->id,
            'promotion_from_post_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            // Minted through the real generator, not hardcoded.
            //
            // This fixture starts the original at Approved rather than driving
            // it through submit/approve — it is the setup, not the thing under
            // test. But hardcoding the code left the per-department sequence at
            // zero, so the *next* proposal to be approved for real was handed
            // `DPC-2026-HE-0001` again and collided. Nothing in production can reach
            // that state, because in production the original consumed the
            // sequence too. Found by TD-23's fix, which was the first test to
            // approve a second proposal for real.
            //
            // Still the first call, so the value is unchanged: DPC-2026-HE-0001.
            'dpc_code' => app(IdentifierSequenceService::class)
                ->next($this->department, IdentifierSequenceService::SERIES_DPC, 2026),
            'forwarded_at' => now(),
        ]);

        // The DPC is allotted to this AO and AH (2026-10-26), as the Department
        // Admin would; nobody else in the department acts on it.
        $this->allot($this->proposal, $this->authorizedOfficer, $this->administrativeHead);

        /*
         * **The Seniority-List-must-match-the-Zone rule is stood down for these
         * fixtures**, and the reason is worth recording rather than hiding.
         *
         * These tests declare between two and six officers to make a *scoring*
         * point, and the vacancy below is one — which sizes the zone at 5. Two
         * officers therefore cannot satisfy the rule, and there is no officer
         * count that satisfies it for every test in this family (the zone takes
         * the values 5, 8, 10 … and never 2, 3, 4 or 6).
         *
         * Left on, `makeProposalSubmittable()` would *rewrite* the seniority
         * list to five synthetic officers whenever a Review is submitted — and
         * because the chart is projected from that list, it would replace the
         * chart the Review inherited, which is the very thing several of these
         * tests assert about. Standing the rule down keeps each test measuring
         * what it was written to measure.
         *
         * The rule itself is covered directly by DpcVacancyCalculationTest, and
         * by CompletesDpcProposals in the M4 suite, so nothing is left unproven.
         */
        Config::set('dpc.vacancy.enforce_seniority_matches_zone', false);

        /*
         | One vacancy, **stated on the chart** (business directive, 2026-09-02).
         |
         | This used to insert only the breakdown row, which was enough while the
         | base vacancy was `Sanctioned − Working` and an empty chart therefore
         | produced one on its own. A vacancy is now a chart row whose status is
         | `vacant`, and the breakdown is derived from it — so the chart has to say
         | so, or the section reads as a breakdown nothing evidences.
         |
         | Written directly rather than through `DpcProposalService`, as the
         | breakdown row below always was: by this point the proposal is past Draft
         | and the service would rightly refuse to edit it. A fixture is not the
         | application.
         */
        $this->recordSanctionedStrength($this->proposal, 1);

        /*
         | And a 100%-promotion Recruitment Rule, which became a **required input**
         | with the business directive of 2026-09-02: the promotion vacancy is
         | `promotion chairs − promotion chairs held`, and without a rule nothing
         | says how many of the sanctioned chairs are promotion chairs. A real
         | proposal inherits one from the Pre-DPC it was raised under; this fixture
         | builds its posts directly, so it registers its own.
         */
        if ($this->proposal->promotionToPost !== null) {
            RecruitmentRule::query()->firstOrCreate(
                ['post_id' => $this->proposal->promotionToPost->id, 'version' => 1],
                [
                    'government_order_number' => 'GO/PROCEEDING/1',
                    'direct_recruitment_pct' => 0,
                    'promotion_recruitment_pct' => 100,
                    'effective_from' => '2020-01-01',
                    'status' => MasterStatus::Active->value,
                ],
            );

            $this->proposal->forceFill(['recruitment_rule_id' => null])->save();
        }

        $this->proposal->workingOfficers()->create([
            'sn' => 1,
            'status' => DpcWorkingOfficer::STATUS_VACANT,
            'reservation_category_id' => $this->urCategoryId(),
        ]);

        $this->proposal->vacancies()->create([
            'vacancy_type_id' => VacancyType::query()->where('code', 'clear')->firstOrFail()->id,
            'reservation_category_id' => $this->urCategoryId(),
            'count' => 1,
        ]);

        /*
         | Seated before allotting: a DPC is allotted to a serving member of its own
         | committee (business, 2026-09-06), and the qualification is committee
         | membership rather than a Commission role (correction, 2026-09-07).
         */
        $this->seatCommittee($this->proposal);

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($this->proposal->fresh(), $this->memberOne, request());
        $scheduling->schedule($this->proposal->fresh(), $this->memberOne, request(), Carbon::parse('2026-07-12'), '14:00');

        $this->proceeding = app(ProceedingService::class)
            ->open($this->proposal->fresh(), $this->memberOne, request());
    }

    protected function urCategoryId(): int
    {
        return ReservationCategory::query()->where('code', 'UR')->firstOrFail()->id;
    }

    protected function stCategoryId(): int
    {
        $st = ReservationCategory::query()->where('code', 'ST')->first()
            ?? ReservationCategory::query()->where('is_reserved', true)->firstOrFail();

        return $st->id;
    }

    /**
     * @param  array<int, string>  $grades
     * @param  list<int>  $propertyMissingYears
     */
    protected function officer(
        int $sn,
        string $name,
        int $categoryId,
        array $grades,
        array $propertyMissingYears = [],
    ): void {
        // The officer joins the Seniority List, which projects the chart row —
        // the chart is no longer somewhere an officer can be invented (business
        // directive, 2026-08-22). Delegated so the two-step flow lives in one
        // place; see SeedsEvaluationCharts.
        $this->seedOneOfficer($this->proposal, $sn, $name, $categoryId, $grades, $propertyMissingYears);
    }

    protected function corrections(): ProceedingCorrectionService
    {
        return app(ProceedingCorrectionService::class);
    }

    protected function calculate(): void
    {
        app(ProceedingCalculationService::class)->calculate($this->proceeding->fresh(), $this->memberOne);
    }

    protected function latestRun(): DpcCalculationRun
    {
        $run = app(ProceedingCalculationService::class)->latestRun($this->proceeding->fresh());

        if ($run === null) {
            throw new \RuntimeException('No calculation has been run.');
        }

        return $run;
    }
}
