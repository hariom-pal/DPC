<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcVacancy;
use App\Domain\Dpc\Models\DpcVacancyCalculation;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Vacancy Management as the single source of a new proposal's vacancy figures
 * (2026-10-17): the Administrator's finalized record, read-only to the AO, the same
 * results as the Working Officers chart for the same facts, and no change to
 * proposals raised before.
 */
class VacancyManagementIntegrationTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const YEAR = 2026;

    private User $admin;

    private User $ao;

    private Department $department;

    private DepartmentPost $from;

    private DepartmentPost $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk', 'local'));

        // Single pool, as in production today.
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->from = $this->makePost('Lecturer');
        $this->to = $this->makePost('Deputy Director');

        // The promotion post's Recruitment Rule; each scenario sets its share.
        $this->rule = RecruitmentRule::query()->create([
            'post_id' => $this->to->id, 'version' => 1, 'government_order_number' => 'GO/RR/1',
            'direct_recruitment_pct' => 25, 'promotion_recruitment_pct' => 75,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
    }

    private RecruitmentRule $rule;

    private function makePost(string $name, ?Department $department = null): DepartmentPost
    {
        $post = new DepartmentPost([
            'department_id' => ($department ?? $this->department)->id,
            'name' => $name,
            'class_level' => 'II',
            'effective_from' => '2020-01-01',
        ]);
        $post->status = MasterStatus::Active;
        $post->active_flag = 1;
        $post->save();

        return $post;
    }

    private function raise(string $source = 'vacancy_master'): DpcProposal
    {
        config(['dpc.vacancy_master.source_for_new_proposals' => $source]);
        $proposal = $this->raiseDpc($this->ao, $this->from, $this->to);
        config(['dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        return $proposal;
    }

    /** The promotion share the proposal's own Recruitment Rule gives. */
    private function share(DpcProposal $proposal, string $promotion): void
    {
        $rule = $proposal->recruitment_rule_id === null ? $this->rule : RecruitmentRule::query()->findOrFail($proposal->recruitment_rule_id);
        $rule->forceFill(['promotion_recruitment_pct' => $promotion, 'direct_recruitment_pct' => 100 - (float) $promotion])->save();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
    }

    /**
     * The Administrator's record, through the service the screens use.
     *
     * @param  list<array{reason: string, quota: string, count: int}>  $anticipated
     */
    private function finalize(int $sanctioned, int $promotion, int $direct, array $anticipated = [], ?Department $department = null, int $year = self::YEAR, ?DepartmentPost $post = null): VacancyPosition
    {
        $service = app(VacancyMasterService::class);
        $position = $service->openDraft($department ?? $this->department, $post ?? $this->to, $year, $this->admin);

        $service->saveSanctionedStrength($position, ['sanctioned_total' => $sanctioned, 'order_number' => 'SANC/'.$year, 'order_date' => $year.'-01-02'], $this->pdf(), $this->admin);
        $service->saveWorkingStrength($position->fresh(), [
            'working_total' => $promotion + $direct, 'working_promotion' => $promotion, 'working_direct' => $direct, 'working_deputation' => 0,
            'order_number' => 'EST/'.$year, 'order_date' => $year.'-01-02',
        ], $this->pdf(), $this->admin);

        foreach ($anticipated as $i => $entry) {
            $service->addEntry($position->fresh(), [
                'kind' => 'anticipated', 'reason' => $entry['reason'], 'vacancy_count' => $entry['count'],
                'effective_date' => $year.'-06-30', 'occupant_quota' => $entry['quota'], 'reconciliation' => 'counted_in_working',
                'order_number' => 'RET/'.$i, 'order_date' => $year.'-01-10',
            ], $this->pdf(), $this->admin);
        }

        return $service->finalize($position->fresh(), $this->admin);
    }

    /**
     * The same facts as a Working Officers chart: promotion and direct officers, one
     * promotion officer per anticipated retirement retiring on 30 June, the rest vacant.
     */
    private function chart(DpcProposal $proposal, int $sanctioned, int $promotion, int $direct, int $retiring): void
    {
        $category = ReservationCategory::query()->where('code', 'UR')->value('id');
        $rows = [];

        for ($i = 0; $i < $sanctioned; $i++) {
            $sn = $i + 1;
            $row = ['sn' => $sn, 'reservation_category_id' => $category];

            if ($i < $promotion + $direct) {
                $row += [
                    'status' => DpcWorkingOfficer::STATUS_WORKING,
                    'source' => $i < $promotion ? DpcWorkingOfficer::SOURCE_PROMOTION : DpcWorkingOfficer::SOURCE_DIRECT,
                    'name' => 'Officer '.$sn,
                    'dob' => '1970-01-01',
                    'date_of_retirement' => $i < $retiring ? self::YEAR.'-06-30' : null,
                ];
            } else {
                $row['status'] = DpcWorkingOfficer::STATUS_VACANT;
            }

            $rows[] = $row;
        }

        $service = app(DpcProposalService::class);
        $service->saveWorkingOfficers($proposal, $rows, $this->ao);
        $service->saveVacancies($proposal->refresh(), $this->ao);
    }

    // ================================================== Admin: one entry point

    public function test_the_admin_has_one_vacancy_management_entry_and_opens_a_record_by_department_post_and_year(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.vacancy-management.index'), false)
            ->assertDontSee('admin/vacancy-types', false);

        $this->actingAs($this->admin)
            ->get(route('admin.vacancy-management.index', ['department_id' => $this->department->id]))
            ->assertOk()
            ->assertSee('Deputy Director')
            ->assertSee('Lecturer');

        $this->actingAs($this->admin)
            ->get(route('admin.vacancy-management.go', ['department_id' => $this->department->id, 'post' => 'Deputy Director', 'year' => self::YEAR]))
            ->assertRedirect(route('admin.departments.vacancy-master.show', [$this->department, self::YEAR, $this->to]));

        // A post of another department is not offered through this one.
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $this->makePost('Assistant Engineer', $other);

        $this->actingAs($this->admin)
            ->get(route('admin.vacancy-management.go', ['department_id' => $this->department->id, 'post' => 'Assistant Engineer', 'year' => self::YEAR]))
            ->assertSessionHasErrors('post');
    }

    public function test_the_record_list_is_filtered_and_keeps_each_years_figures(): void
    {
        $this->finalize(12, 7, 2, [], null, 2025);
        $this->finalize(20, 15, 2, [], null, 2026);

        $this->actingAs($this->admin)->get(route('admin.vacancy-management.index', ['year' => 2025]))
            ->assertOk()
            ->assertSee('data-vacancy-record', false)
            ->assertSeeInOrder(['2025', 'Deputy Director', '12']);

        $years = VacancyPosition::query()->where('post_name', 'Deputy Director')->orderBy('vacancy_year')->pluck('sanctioned_strength', 'vacancy_year')->all();
        $this->assertSame([2025 => 12, 2026 => 20], $years, 'Each year keeps its own sanctioned strength.');
    }

    public function test_only_the_administrator_reaches_vacancy_management(): void
    {
        $this->actingAs($this->ao)->get(route('admin.vacancy-management.index'))->assertForbidden();
        $this->actingAs($this->ao)->post(route('admin.departments.vacancy-master.open', [$this->department, self::YEAR, $this->to]))->assertForbidden();
    }

    public function test_the_vacancy_type_screens_are_gone_and_the_rows_survive(): void
    {
        $this->assertFalse(Route::has('admin.vacancy-types.index'));
        $this->actingAs($this->admin)->get(route('admin.master-data.template', 'vacancy-types'))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.master-data.import', 'vacancy-types'), [
            'file' => UploadedFile::fake()->createWithContent('t.csv', "code,name_en,name_hi,status\nX,X,X,active\n"),
        ])->assertNotFound();

        $this->assertSame(['clear', 'from_deputation', 'from_promotion', 'from_retirement'], VacancyType::query()->orderBy('code')->pluck('code')->all());
    }

    // ============================================= the same results as the chart

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: string}>
     */
    public static function scenarios(): array
    {
        return [
            // 12 × 75% = 9 promotion posts; 7 − 1 retiring held → 3 promotion, 1 direct.
            'retirement in the year' => [12, 7, 2, 1, '75.00'],
            // Rule 1.3: 10 × 55% = 5.5 → 6 to promotion (the .5 goes to promotion).
            'rule 1.3 half to promotion' => [10, 3, 2, 0, '55.00'],
            // Full promotion post, no direct quota.
            'all promotion' => [8, 5, 0, 2, '100.00'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_the_admin_record_gives_the_same_figures_as_the_chart(int $sanctioned, int $promotion, int $direct, int $retiring, string $share): void
    {
        $chart = $this->raise('chart');
        $master = $this->raise('vacancy_master');
        $this->share($master, $share);

        $anticipated = $retiring > 0 ? [['reason' => 'retirement', 'quota' => 'promotion', 'count' => $retiring]] : [];
        $this->finalize($sanctioned, $promotion, $direct, $anticipated);
        $this->chart($chart->fresh(), $sanctioned, $promotion, $direct, $retiring);

        $calc = app(VacancyCalculationService::class);
        $a = $calc->breakdown($chart->fresh());
        $b = $calc->breakdown($master->fresh());

        foreach (['sanctionedStrength', 'promotionPercentage', 'promotionChairs', 'directChairs', 'promotionChairsHeld', 'directChairsHeld', 'promotionVacancy', 'directRecruitmentVacancy', 'totalVacancy', 'retirementVacancy'] as $figure) {
            $this->assertSame($a->{$figure}, $b->{$figure}, $figure);
        }

        $this->assertSame($calc->summary($chart->fresh())['required_seniority'], $calc->summary($master->fresh())['required_seniority']);
    }

    // ===================================================== the AO's proposal

    public function test_the_ao_sees_the_finalized_figures_read_only_and_cannot_enter_a_chart(): void
    {
        $proposal = $this->raise();
        $this->share($proposal, '75.00');
        $this->finalize(12, 7, 2, [['reason' => 'retirement', 'quota' => 'promotion', 'count' => 1]]);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $proposal))
            ->assertOk()
            ->assertSee('Vacancy position (from Admin)')
            ->assertSee('data-admin-vacancy="promotion_vacancy">3<', false)
            ->assertSee('data-admin-vacancy="sanctioned">12<', false)
            ->assertDontSee('name="officers[', false);

        // The proposal's own page shows the same record in its S16 section.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))
            ->assertOk()
            ->assertSee('data-admin-vacancy="promotion_vacancy">3<', false);

        config(['dpc.manual_entry.working_officers' => true]);
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $proposal), [
            'officers' => [['sn' => 1, 'status' => 'vacant', 'reservation_category_id' => ReservationCategory::query()->value('id')]],
        ])->assertForbidden();

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $proposal), [
            'template' => 'currently_working_us',
            'file' => UploadedFile::fake()->createWithContent('w.csv', "SN,STATUS\n1,vacant\n"),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, $proposal->workingOfficers()->count());
    }

    public function test_without_a_finalized_record_the_proposal_says_so_and_cannot_be_submitted(): void
    {
        $proposal = $this->raise();

        // A draft is not authoritative either.
        app(VacancyMasterService::class)->openDraft($this->department, $this->to, self::YEAR, $this->admin);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $proposal))
            ->assertOk()
            ->assertSee('Vacancy information for the selected Department, Post and Year has not yet been finalized by Admin.');

        $section = collect(app(DpcProposalCompletenessService::class)->checklist($proposal))->firstWhere('key', 'vacancy_master');
        $this->assertFalse($section['complete']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal));
        $this->assertSame(DpcProposalStatus::Draft, $proposal->fresh()->status);
    }

    public function test_a_record_of_another_year_or_department_is_never_used(): void
    {
        $proposal = $this->raise();
        $this->share($proposal, '75.00');

        $this->finalize(12, 7, 2, [], null, 2025);
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $otherPost = $this->makePost('Deputy Director', $other);
        RecruitmentRule::query()->create([
            'post_id' => $otherPost->id, 'version' => 1, 'government_order_number' => 'GO/RR/2',
            'direct_recruitment_pct' => 25, 'promotion_recruitment_pct' => 75,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $this->finalize(12, 7, 2, [], $other, self::YEAR, $otherPost);

        $breakdown = app(VacancyCalculationService::class)->breakdown($proposal->fresh());
        $this->assertNull($breakdown->sanctionedStrength);
        $this->assertNull($breakdown->promotionVacancy);
    }

    public function test_submission_pins_the_record_writes_the_vacancy_and_a_later_revision_changes_nothing(): void
    {
        $proposal = $this->raise();
        $this->share($proposal, '75.00');
        $position = $this->finalize(12, 7, 2, [['reason' => 'retirement', 'quota' => 'promotion', 'count' => 1]]);

        $this->makeProposalSubmittable($proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal))->assertSessionHasNoErrors();

        $proposal->refresh();
        $this->assertSame(DpcProposalStatus::Submitted, $proposal->status);
        $this->assertSame($position->id, $proposal->vacancy_position_id);
        $this->assertSame(3, (int) $proposal->vacancies()->sum('count'), 'The promotion vacancy the scoring engine fills.');
        $this->assertSame(1, $proposal->vacancies()->whereHas('vacancyType', fn ($q) => $q->where('code', 'from_promotion'))->sum('count'));
        $this->assertSame(1, DpcVacancyCalculation::query()->where('dpc_proposal_id', $proposal->id)->count());

        // The Administrator revises the year's record afterwards.
        $service = app(VacancyMasterService::class);
        $draft = $service->revise($position->fresh(), 'Strength revised.', $this->admin);
        $service->saveSanctionedStrength($draft, ['sanctioned_total' => 20, 'order_number' => 'SANC/2', 'order_date' => '2026-03-01', 'correction_reason' => 'New order.'], $this->pdf(), $this->admin);
        $service->finalize($draft->fresh(), $this->admin);

        $breakdown = app(VacancyCalculationService::class)->breakdown($proposal->fresh());
        $this->assertSame(12, $breakdown->sanctionedStrength, 'A submitted proposal keeps the revision it was submitted with.');
        $this->assertSame(3, $breakdown->promotionVacancy);
    }

    public function test_the_promotion_vacancy_reaches_the_main_list(): void
    {
        $proposal = $this->raise();
        $this->share($proposal, '100.00');
        $this->finalize(8, 6, 0);

        $this->makeProposalSubmittable($proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal))->assertSessionHasNoErrors();

        $this->assertSame(2, (int) DpcVacancy::query()->where('dpc_proposal_id', $proposal->id)->sum('count'));
        $this->assertSame(2, app(VacancyCalculationService::class)->summary($proposal->fresh())['promotion_vacancy']);
    }

    // ===================================================== history is protected

    public function test_a_proposal_raised_on_the_chart_keeps_the_chart(): void
    {
        $chart = $this->raise('chart');
        $this->share($chart, '75.00');
        $this->finalize(12, 7, 2);
        $this->chart($chart->fresh(), 12, 7, 2, 0);

        $this->assertSame('chart', $chart->fresh()->vacancy_source);
        $this->assertFalse($chart->fresh()->usesVacancyMaster());
        $this->assertSame(12, $chart->workingOfficers()->count(), 'Its chart rows are still the source.');
        $this->assertSame(['clear'], $chart->vacancies()->with('vacancyType')->get()->pluck('vacancyType.code')->unique()->values()->all());
    }

    public function test_another_departments_officer_cannot_see_the_figures(): void
    {
        $proposal = $this->raise();
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($intruder)->get(route('dpc-proposals.working-officers', $proposal))->assertForbidden();
    }
}
