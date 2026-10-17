<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\RecordGapService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Import\Services\ExcelImportService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\Role;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three demonstration CSVs, imported through the real flow.
 *
 * **`SAMPLE_DATA_GUIDE.md` claims these figures are "what the engine actually
 * produced, not what the files were designed to produce".** This is what makes
 * that sentence true, and keeps it true: the sample data is walked through a live
 * Pre-DPC, a real Recruitment Rule, `ExcelImportService`, the vacancy engine and
 * `DpcCalculationService`, and the numbers the demonstration script promises on
 * screen are asserted against what comes back.
 *
 * **Why it is worth pinning numbers here, having declined to elsewhere.** A
 * presenter reads an expected result off the page and compares it with a screen in
 * front of a customer. That is a contract between a document and a dataset, and
 * the only way it stays honest is for a change to either one to fail here.
 *
 * The live DPC is **Block Education Officer → Additional Director, Education**,
 * and it is raised from the **seeded** order for that post — the rung carries two
 * feeders, Joint Director and Block Education Officer, and this DPC names the one
 * it runs. Building a Pre-DPC inside the test instead would prove only that the
 * test can sanction its own promotion.
 */
class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLES = 'docs/demo/sample-data/';

    public function test_the_sample_data_produces_the_figures_the_demonstration_promises(): void
    {
        [$proposal, $ao] = $this->liveDpc();

        $this->import($proposal, ImportTemplate::CurrentlyWorkingUs, 'demo-working-officers.csv', $ao);
        app(DpcProposalService::class)->saveVacancies($proposal->refresh(), $ao);

        // S16 — ten chairs: six substantively held, one deputed, three vacant.
        $vacancy = app(VacancyCalculationService::class)->summary($proposal->refresh());

        $this->assertSame(10, $vacancy['sanctioned'], 'Additional Director is a ten-chair post.');
        $this->assertSame(7, $vacancy['working']);
        $this->assertSame(10, $vacancy['breakdown']->chartRows, 'The chart must account for every sanctioned chair.');
        $this->assertSame(10, $vacancy['breakdown']->promotionChairs, '100% promotion, so every chair is a promotion chair.');
        $this->assertSame(6, $vacancy['breakdown']->promotionChairsHeld, 'The deputed officer holds no quota.');
        $this->assertSame(0, $vacancy['breakdown']->directChairs);
        $this->assertSame(4, $vacancy['total_vacancy'], 'Vacancy 4 of 10 sanctioned.');
        $this->assertSame(4, $vacancy['classified_total']);

        $this->assertSame(
            ['OBC' => 1, 'SC' => 1, 'ST' => 1, 'UR' => 1],
            $proposal->refresh()->vacancies
                ->groupBy(fn ($v) => $v->reservationCategory?->code)
                ->map->sum('count')->sortKeys()->all(),
            'One vacancy in each of UR, ST, OBC and SC.',
        );

        // S17 — the list is the source of truth for who is evaluated, and it
        // provisions a Promotion Candidate account per officer.
        $usersBefore = User::query()->count();

        $this->import($proposal, ImportTemplate::FinalSeniorityListUs, 'demo-seniority-list.csv', $ao);

        $this->assertSame(10, $proposal->refresh()->seniorityEntries()->count());
        $this->assertSame(10, $proposal->refresh()->evaluationRows()->count(), 'The chart is projected from the list.');
        $this->assertSame($usersBefore + 10, User::query()->count(), 'Ten candidate accounts are provisioned.');

        $this->assertTrue(
            User::query()->where('employee_number', 'SE/BEO/1001')->exists(),
            'Candidates are provisioned under the employee number the list carries, not a minted one.',
        );

        // S18 — five recorded years per officer, not six: the 2026 columns are blank.
        $this->import($proposal, ImportTemplate::EvaluationChartUs, 'demo-evaluation-chart.csv', $ao);

        $rowIds = $proposal->refresh()->evaluationRows()->pluck('id');

        $this->assertSame(50, DpcEvaluationCrGrade::whereIn('dpc_evaluation_row_id', $rowIds)->count());
        $this->assertSame(50, DB::table('dpc_evaluation_loyalty_flags')->whereIn('dpc_evaluation_row_id', $rowIds)->count());
        $this->assertSame(50, DB::table('dpc_evaluation_property_flags')->whereIn('dpc_evaluation_row_id', $rowIds)->count());

        // Calculate — Main 4, and the Main List equals the Total Vacancy.
        $base = $this->calculate($proposal->refresh(), $ao);

        $this->assertSame(
            ['main' => 4, 'waiting' => 3, 'none' => 3],
            $this->countsOf($base),
            'Main 4 - Waiting 3 - Not eligible 3.',
        );

        $this->assertSame(
            [
                1 => ['Shri Ramesh Chandra Verma', 'UR', 20, 'EL', 'main'],
                2 => ['Smt. Sunita Rani Patel', 'OBC', 18, 'EL', 'main'],
                3 => ['Shri Jagdish Prasad Netam', 'ST', 18, 'EL', 'main'],
                4 => ['Smt. Kavita Bhaskar', 'SC', 15, 'EL', 'main'],
                5 => ['Shri Prakash Kumar Yadav', 'OBC', 13, 'EL', 'waiting'],
                6 => ['Shri Naresh Kumar Markam', 'ST', 13, 'EL', 'waiting'],
                7 => ['Smt. Anjali Shrivastava', 'UR', 14, 'EL', 'waiting'],
                8 => ['Shri Suresh Kumar Sahu', 'OBC', 7, 'NE', 'none'],
                9 => ['Shri Dinesh Kumar Kashyap', 'SC', 12, 'NE', 'none'],
                10 => ['Smt. Priya Dubey', 'UR', 15, 'NE', 'none'],
            ],
            $this->rowsOf($base),
        );

        $this->assertTrue(
            $base->results()->where('sn', 1)->firstOrFail()->is_super_seat,
            'Serial 1 is the Super Seat.',
        );

        $this->assertSame(
            $vacancy['total_vacancy'],
            $this->countsOf($base)['main'],
            'The Main List equals the Total Vacancy, and that is the point of the screen.',
        );
    }

    /**
     * The Circulation variant: one mandatory CR is not on record.
     *
     * **A gap is only a hold once it is justified.** Unjustified it is a plain
     * shortfall and the officer is Not Eligible; with a reason and a supporting
     * document the promotion is *held* — `CIR` — and the candidate continues in
     * the DPC. Both halves are asserted, because the difference between them is
     * the whole reason the Circulation screen exists.
     */
    public function test_the_with_hold_variant_holds_serial_four(): void
    {
        [$proposal, $ao] = $this->liveDpc();

        $this->import($proposal, ImportTemplate::CurrentlyWorkingUs, 'demo-working-officers.csv', $ao);
        app(DpcProposalService::class)->saveVacancies($proposal->refresh(), $ao);
        $this->import($proposal, ImportTemplate::FinalSeniorityListUs, 'demo-seniority-list.csv', $ao);
        $this->import($proposal, ImportTemplate::EvaluationChartUs, 'demo-evaluation-chart-with-hold.csv', $ao);

        $unjustified = $this->calculate($proposal->refresh(), $ao);

        $this->assertSame('NE', $unjustified->results()->where('sn', 4)->firstOrFail()->status()->value);
        $this->assertSame(['main' => 3, 'waiting' => 3, 'none' => 4], $this->countsOf($unjustified));

        app(RecordGapService::class)->record(
            $proposal->refresh()->evaluationRows()->where('sn', 4)->firstOrFail(),
            DpcEvaluationRecordGap::KIND_CR,
            2023,
            RecordReason::query()->where('code', 'record_not_available')->firstOrFail(),
            null,
            new UploadedFile(
                base_path(self::SAMPLES.'demo-missing-record-justification.pdf'),
                'demo-missing-record-justification.pdf',
                'application/pdf',
                null,
                true,
            ),
            $ao,
        );

        $held = $this->calculate($proposal->refresh(), $ao);

        $this->assertSame(
            'CIR',
            $held->results()->where('sn', 4)->firstOrFail()->status()->value,
            'Justified, the promotion is held rather than refused.',
        );

        $this->assertSame(
            ['main' => 3, 'waiting' => 3, 'none' => 4],
            $this->countsOf($held),
            'A held promotion must not fill a seat, so Serial 4 stays off the Main List.',
        );
        $this->assertTrue(true);
    }

    /**
     * The categories the demonstration's officers hold, by seniority number — what
     * the list carried until it was simplified on 2026-10-17.
     */
    private function recordCategories(DpcProposal $proposal, User $actor): void
    {
        $codes = [1 => 'UR', 2 => 'OBC', 3 => 'ST', 4 => 'SC', 5 => 'OBC', 6 => 'ST', 7 => 'UR', 8 => 'OBC', 9 => 'SC', 10 => 'UR'];

        foreach ($proposal->evaluationRows()->get() as $row) {
            app(EmployeeDetailsService::class)->saveBasic($row, [
                'reservation_category_id' => ReservationCategory::query()->where('code', $codes[$row->sn])->value('id'),
                'date_of_posting_on_promotion_from_post' => $row->date_of_posting_on_promotion_from_post?->toDateString(),
            ], $actor);
        }
    }

    private function import(DpcProposal $proposal, ImportTemplate $template, string $file, User $actor): void
    {
        $this->actingAs($actor);
        $service = app(ExcelImportService::class);

        $path = base_path(self::SAMPLES.$file);
        $upload = new UploadedFile($path, $file, 'text/csv', null, true);

        $batch = $service->preview(
            $proposal->refresh(),
            $template,
            $upload,
            $actor,
            $template === ImportTemplate::FinalSeniorityListUs ? $proposal->seniorityReferenceDate() : null,
        );

        $this->assertSame(
            0,
            $batch->rejected_count,
            "The demonstration is handed [{$file}] and it must import clean.",
        );

        $this->assertSame(10, $batch->total_rows, "[{$file}] is a ten-row file.");

        $service->commit($batch, $actor);

        // The simplified Seniority List carries no category; the Authorized Officer
        // records each officer's on the Employee Details screen, as here.
        if ($template === ImportTemplate::FinalSeniorityListUs) {
            $this->recordCategories($proposal->refresh(), $actor);
        }
    }

    /**
     * The live DPC of `SAMPLE_DATA_GUIDE.md`, built the way §5.4 and §5.5 build it.
     *
     * @return array{0: DpcProposal, 1: User, 2: int}
     */
    private function liveDpc(): array
    {
        $this->seed(DemoSeeder::class);

        $department = Department::query()->where('code', 'SE')->firstOrFail();
        $ao = $this->officerOf($department, Role::AuthorizedOfficer);
        $ah = $this->officerOf($department, Role::AdministrativeHead);

        $ade = $this->postOf($department, 'Additional Director, Education');
        $beo = $this->postOf($department, 'Block Education Officer');

        // Ten chairs, from the seeded master rather than forced here — the sample
        // data does not balance on any other number, so this is an assertion.
        $this->assertSame(
            10,
            $ade->sanctioned_strength,
            'Additional Director must be a ten-chair post on the Department Post Mapping.',
        );

        /*
         | The order that sanctions this promotion, and it is a **seeded** order.
         |
         | Block Education Officer → Additional Director is the demonstration's
         | sample record, so the ladder sanctions it: the rung carries two feeders
         | and this DPC names which one it runs. Raising the DPC from the seeded
         | order rather than building a Pre-DPC here is the point — if the pair ever
         | stops being sanctioned, this fails instead of quietly passing on an order
         | the test made for itself.
         */
        $preDpc = PreDpcProposal::query()
            ->where('department_id', $department->id)
            ->where('promotion_to_post_id', $ade->id)
            ->whereHas('promotionSources', fn ($q) => $q->where('promotion_from_post_id', $beo->id))
            ->firstOrFail();

        $this->assertSame(
            'approved',
            $preDpc->status?->value,
            'The sanctioning order has to be approved before a DPC can be raised from it.',
        );

        $proposal = app(DpcProposalService::class)->createFromPreDpc($preDpc, $ao, [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'legal_stay' => false,

            // Which of the order's two feeders this DPC runs. Named, never inferred.
            'promotion_from_post_id' => $beo->id,
        ]);

        /*
         | The order's requirement for *this* feeder, which is what refuses SN 10.
         |
         | It is read off the promotion source and not off the proposal: the
         | proposal's `promotion_duration_years` comes from the feeder post's own
         | mapping, which Block Education Officer does not carry, so the five years
         | live here and nowhere else.
         */
        $this->assertSame(
            5,
            $preDpc->promotionSources
                ->firstWhere('promotion_from_post_id', $beo->id)
                ?->min_experience_years,
            'The Block Education Officer feeder requires five years.',
        );

        app(DpcCommitteeService::class)->compose($proposal, [
            [
                'user' => $ah, 'capacity' => CommitteeCapacity::Member,
                'is_female' => true, 'is_st' => true, 'is_bhar_sadhak_sachiv' => true,
            ],
            [
                'user' => User::query()->where('email', 'member1@cgpsc.gov.in')->firstOrFail(),
                'capacity' => CommitteeCapacity::Member, 'is_sc' => true,
            ],
            [
                'user' => null,
                'name' => 'Shri Sanjay Kumar Agrawal',
                'designation' => 'Deputy Secretary, General Administration Department',
                'mobile' => '9000009003',
                'capacity' => CommitteeCapacity::Member,
                'is_obc' => true, 'is_vibhag_sachiv' => true,
            ],
        ], $ao);

        return [$proposal->refresh(), $ao];
    }

    private function calculate(DpcProposal $proposal, User $actor): DpcCalculationRun
    {
        $this->actingAs($actor);

        return app(DpcCalculationService::class)->calculate($proposal, $actor);
    }

    /**
     * How many candidates landed on each list, keyed by list and ordered so the
     * failure message reads main, waiting, then neither.
     *
     * @return array<string, int>
     */
    private function countsOf(DpcCalculationRun $run): array
    {
        $counts = $run->results()->get()
            ->countBy(fn ($r) => $r->list_type?->value ?? 'none')
            ->all();

        return array_filter([
            'main' => $counts['main'] ?? 0,
            'waiting' => $counts['waiting'] ?? 0,
            'none' => $counts['none'] ?? 0,
        ]);
    }

    /**
     * One row per candidate — name, category, score, status and list — keyed by
     * serial, which is what the demonstration script prints beside the screen.
     *
     * @return array<int, array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    private function rowsOf(DpcCalculationRun $run): array
    {
        return $run->results()->get()->sortBy('sn')
            ->mapWithKeys(fn ($r) => [$r->sn => [
                $r->officer_name,
                $r->reservation_category_code,
                (int) ($r->score_breakdown['total'] ?? 0),
                $r->status()->value,
                $r->list_type?->value ?? 'none',
            ]])->all();
    }

    private function officerOf(Department $d, Role $r): User
    {
        // The posting is the assignment, not a column on the user.
        return User::query()
            ->whereHas('assignments', fn ($q) => $q
                ->whereNotNull('active_flag')
                ->where('department_id', $d->id)
                ->where('role', $r->value))
            ->firstOrFail();
    }

    private function postOf(Department $d, string $name): DepartmentPost
    {
        return DepartmentPost::query()->where('department_id', $d->id)->where('name', $name)->firstOrFail();
    }
}
