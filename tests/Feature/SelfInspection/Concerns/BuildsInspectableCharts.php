<?php

declare(strict_types=1);

namespace Tests\Feature\SelfInspection\Concerns;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use App\Enums\MasterStatus;
use Database\Seeders\LookupMasterSeeder;
use Illuminate\Support\Carbon;

/**
 * The smallest fixture Self-Inspection needs: a department, a proposal under
 * consideration, and an officer row on its evaluation chart.
 *
 * **Deliberately not the full M4 pipeline.** M10 reads the chart; it does not
 * produce one, and driving submit → approve → allot → schedule for every test
 * here would make them slow and would test M4 rather than M10. The one test that
 * genuinely needs a scheduled sitting builds that part explicitly, so the
 * dependency is visible where it matters instead of paid for everywhere.
 */
trait BuildsInspectableCharts
{
    protected Department $department;

    protected DpcProposal $proposal;

    protected DpcEvaluationRow $row;

    protected function bootChart(): void
    {
        /*
         | These fixtures drive the **factor** form, and `otp` became the default
         | driver on 2026-09-07 — an officer now proves they hold the mobile number
         | their department recorded. The driver is pinned here so each test says
         | which of the two it is about; `SelfInspectionOtpTest` covers the other.
         */
        config(['dpc.self_inspection.authentication.driver' => 'identity_factors']);

        // The lookup masters, seeded rather than hand-built: DpcType and
        // ReservationCategory both carry the `IsMaster` trait and are not
        // mass-assignable, and every other fixture in the suite gets them this
        // way.
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education Department', 'status' => 'active',
        ]);

        $this->proposal = $this->makeProposal();
        $this->row = $this->makeRow($this->proposal);
    }

    protected function makeProposal(
        DpcProposalStatus $status = DpcProposalStatus::Submitted,
        ?string $dpcCode = 'DPC-2026-SE-0001',
    ): DpcProposal {
        $type = DpcType::query()->where('code', 'fresh')->firstOrFail();

        $to = $this->departmentPost('Deputy Director');
        $from = $this->departmentPost('Under Secretary');

        return DpcProposal::query()->create([
            'department_id' => $this->department->id,
            'dpc_type_id' => $type->id,
            'promotion_to_post_id' => $to->id,
            'promotion_from_post_id' => $from->id,
            'proposal_date' => '2026-06-25',
            'status' => $status->value,
            'dpc_code' => $dpcCode,
            'forwarded_at' => now(),
        ]);
    }

    /**
     * An officer row carrying the S51 columns and three years of per-year data.
     *
     * The 2016 property flag is deliberately **null** rather than false: a
     * missing return is why a Circulation DPC exists, and "not recorded" must be
     * distinguishable from "recorded as absent" on this screen too.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRow(DpcProposal $proposal, array $overrides = []): DpcEvaluationRow
    {
        $category = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        /** @var DpcEvaluationRow $row */
        $row = DpcEvaluationRow::query()->create(array_merge([
            'dpc_proposal_id' => $proposal->id,
            'sn' => 1,
            'employee_number' => 'EMP-001',
            'name' => 'Anil Kumar Verma',
            'dob' => '1964-03-20',
            'qualification' => 'MSC',
            'promotion_to_post' => 'Under Secretary',
            'promotion_from_post' => 'Under Secretary',
            'reservation_category_id' => $category->id,
            'date_of_joining_service' => '1990-07-01',
            'date_of_posting_on_promotion_to_post' => '2012-01-01',
            'date_of_posting_on_promotion_from_post' => '2012-01-01',
            'note' => 'C.CASE',
        ], $overrides));

        foreach ([2015 => 'क+', 2016 => 'क', 2017 => 'ख'] as $year => $grade) {
            $row->crGrades()->create(['year' => $year, 'grade' => $grade]);
            $row->loyaltyFlags()->create(['year' => $year, 'flag' => true]);
            $row->propertyFlags()->create([
                'year' => $year, 'flag' => $year === 2016 ? null : true,
            ]);
        }

        return $row->refresh();
    }

    protected function departmentPost(string $name): DepartmentPost
    {
        return DepartmentPost::query()->firstOrCreate(
            ['department_id' => $this->department->id, 'name' => $name],
            ['class_level' => 'I', 'status' => MasterStatus::Active->value],
        );
    }

    /**
     * The three factors the default configuration asks for, answered correctly.
     *
     * **Read from the configuration rather than listed**, so the fixture follows a
     * change to `dpc.self_inspection.authentication.factors` instead of pinning one
     * era of it. The third factor became
     * `date_of_posting_on_promotion_from_post` on 2026-09-06 — see that config
     * entry for why the previous one could not be answered by a candidate awaiting
     * promotion — and a hardcoded list here would have made that change look like a
     * dozen unrelated failures.
     *
     * @return array<string, string>
     */
    protected function correctFactors(?DpcEvaluationRow $row = null): array
    {
        $row ??= $this->row;

        $answers = [];

        foreach (SelfInspectionFactor::configured() as $factor) {
            $value = $row->getAttribute($factor->value);

            $answers[$factor->value] = $value instanceof Carbon
                ? $value->format('Y-m-d')
                : (string) $value;
        }

        return $answers;
    }
}
