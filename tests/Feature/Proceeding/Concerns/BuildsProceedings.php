<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
    use RefreshDatabase;

    protected Department $department;

    protected User $chairman;

    protected User $memberOne;

    protected User $memberTwo;

    protected User $authorizedOfficer;

    protected DpcProposal $proposal;

    protected DpcProceeding $proceeding;

    protected function bootProceeding(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->chairman = User::factory()->create(['department_id' => null, 'name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['department_id' => null, 'name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $this->authorizedOfficer = User::factory()->create([
            'department_id' => $this->department->id, 'name' => 'Authorized Officer',
        ]);
        $this->authorizedOfficer->assignRole(Role::AuthorizedOfficer->value);

        // Class-I -> Class-I, the PPT's own worked case.
        $postFrom = Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600001',
            'forwarded_at' => now(),
        ]);

        $this->proposal->vacancies()->create([
            'vacancy_type_id' => VacancyType::query()->firstOrFail()->id,
            'reservation_category_id' => $this->urCategoryId(),
            'count' => 1,
        ]);

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
        $row = $this->proposal->evaluationRows()->create([
            'sn' => $sn,
            'name' => $name,
            'dob' => '1967-04-09',
            'qualification' => 'BA',
            'reservation_category_id' => $categoryId,
            'tribal_area_service_years' => null,
        ]);

        foreach ($grades as $year => $grade) {
            $row->crGrades()->create(['year' => $year, 'grade' => $grade]);
            $row->loyaltyFlags()->create(['year' => $year, 'flag' => true]);
            $row->propertyFlags()->create([
                'year' => $year,
                'flag' => in_array($year, $propertyMissingYears, true) ? null : true,
            ]);
        }
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
