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

    /**
     * The promotion share: the Pre-DPC's, which a record kept per Pre-DPC reads
     * (2026-10-30), and the Recruitment Rule's, which a chart reads.
     */
    private function share(DpcProposal $proposal, string $promotion): void
    {
        $rule = $proposal->recruitment_rule_id === null ? $this->rule : RecruitmentRule::query()->findOrFail($proposal->recruitment_rule_id);
        $rule->forceFill(['promotion_recruitment_pct' => $promotion, 'direct_recruitment_pct' => 100 - (float) $promotion])->save();
        $proposal->preDpcProposal?->forceFill(['promotion_recruitment_pct' => $promotion, 'direct_recruitment_pct' => 100 - (float) $promotion])->save();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
    }

    /**
     * The department's record, through the service the screens use: sanctioned,
     * our working officers, and one retirement row per officer retiring on 30 June.
     * Opened under the approved Pre-DPC of the post and year (2026-10-30).
     *
     * @param  list<array{reason: string, quota: string, count: int}>  $anticipated
     */
    private function finalize(int $sanctioned, int $promotion, int $direct, array $anticipated = [], ?Department $department = null, int $year = self::YEAR, ?DepartmentPost $post = null): VacancyPosition
    {
        $department ??= $this->department;
        $post ??= $this->to;
        $feeder = $department->is($this->department) ? $this->from : $this->makePost('Feeder '.$post->id, $department);
        $this->sanctioningPreDpc($feeder, $post, $year);

        $service = app(VacancyMasterService::class);
        $position = $service->openDraft($department, $post, $year, $this->admin);

        $service->saveSanctionedStrength($position, ['sanctioned_total' => $sanctioned, 'order_number' => 'SANC/'.$year, 'order_date' => $year.'-01-02'], $this->pdf(), $this->admin);
        $service->saveWorkingStrength($position->fresh(), [
            'working_total' => $promotion + $direct,
            'order_number' => 'EST/'.$year, 'order_date' => $year.'-01-02',
        ], $this->pdf(), $this->admin);

        $n = 0;

        foreach ($anticipated as $entry) {
            for ($i = 0; $i < $entry['count']; $i++) {
                $service->addEntry($position->fresh(), [
                    'kind' => 'anticipated', 'reason' => $entry['reason'], 'officer_name' => 'Retiring Officer '.(++$n),
                    'effective_date' => $year.'-06-30',
                ], null, $this->admin);
            }
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

    public function test_the_admin_sees_the_whole_trail_of_a_record_across_revisions(): void
    {
        $position = $this->finalize(12, 7, 2, [['reason' => 'retirement', 'quota' => 'promotion', 'count' => 1]]);
        $draft = app(VacancyMasterService::class)->revise($position->fresh(), 'Strength revised.', $this->admin);
        app(VacancyMasterService::class)->saveSanctionedStrength($draft, ['sanctioned_total' => 14, 'order_number' => 'SANC/2', 'order_date' => '2026-03-01', 'correction_reason' => 'New order.'], $this->pdf(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.departments.vacancy-master.show', [$this->department, self::YEAR, $this->to]))
            ->assertOk()
            ->assertSee('id="vacancy-trail"', false)
            ->assertSee($this->admin->name)
            // Revision 1's steps and revision 2's, newest first.
            ->assertSeeInOrder([
                'corrected from 12 to 14',
                'Revision 2 opened',
                'finalized (revision 1)',
                'Anticipated — Retiring Officer 1',
                'Working strength for Deputy Director, 2026 recorded',
                'Sanctioned strength for Deputy Director, 2026 recorded: 12',
                'draft opened',
            ]);
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

    /**
     * 2026-10-19: the department's Authorized Officer keeps the records, in the
     * department's own area and for the department only; the Administrator's area
     * is read-only and stays closed to the AO.
     */
    public function test_the_ao_keeps_own_department_records_and_the_admin_only_reads(): void
    {
        $this->actingAs($this->ao)->get(route('admin.vacancy-management.index'))->assertForbidden();

        $this->actingAs($this->ao)->get(route('dashboard'))->assertOk()->assertSee(route('vacancy-management.index'), false);
        $this->actingAs($this->ao)->get(route('vacancy-management.index'))
            ->assertOk()
            ->assertSee('Deputy Director')
            ->assertDontSee('id="vacancy-read-only"', false);

        $this->sanctioningPreDpc($this->from, $this->to, self::YEAR);
        $this->actingAs($this->ao)->post(route('vacancy-records.open', [$this->department, self::YEAR, $this->to]))->assertRedirect();
        $this->assertSame(1, VacancyPosition::query()->where('department_id', $this->department->id)->count());

        // Another department is neither listed nor reachable.
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $this->actingAs($this->ao)
            ->get(route('vacancy-management.go', ['department_id' => $other->id, 'post' => 'Deputy Director', 'year' => self::YEAR]))
            ->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.vacancy-management.index'))->assertOk()->assertSee('id="vacancy-read-only"', false);
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

    // ======================================== the figures a proposal reads

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: string, 4: int, 5: int}>
     */
    public static function scenarios(): array
    {
        return [
            // A = 12 − 9 = 3, C = 1 → 4; 4 × 75% = 3 promotion, 1 direct.
            'retirement in the year' => [12, 9, 1, '75.00', 3, 1],
            // A = 10 − 5 = 5 → 5; 5 × 55% = 2.75 → rule 1.3 rounds to 3 promotion, 2 direct.
            'share rounded' => [10, 5, 0, '55.00', 3, 2],
            // A = 8 − 5 = 3, C = 2 → 5; all promotion.
            'all promotion' => [8, 5, 2, '100.00', 5, 0],
        ];
    }

    /**
     * A record kept per Pre-DPC (2026-10-30): total = A + B + C, the promotion
     * vacancy the Pre-DPC's share of it, read by the proposal unchanged.
     */
    #[DataProvider('scenarios')]
    public function test_the_proposal_reads_the_records_total_and_the_pre_dpc_share(int $sanctioned, int $working, int $retiring, string $share, int $promotion, int $direct): void
    {
        $master = $this->raise('vacancy_master');
        $this->share($master, $share);

        $anticipated = $retiring > 0 ? [['reason' => 'retirement', 'quota' => 'promotion', 'count' => $retiring]] : [];
        $this->finalize($sanctioned, $working, 0, $anticipated);

        $b = app(VacancyCalculationService::class)->breakdown($master->fresh());

        $this->assertSame($sanctioned, $b->sanctionedStrength);
        $this->assertSame($working, $b->workingStrength);
        $this->assertSame($sanctioned - $working + $retiring, $b->totalVacancy);
        $this->assertSame($retiring, $b->retirementVacancy);
        $this->assertSame($promotion, $b->promotionVacancy);
        $this->assertSame($direct, $b->directRecruitmentVacancy);
    }

    // ===================================================== the AO's proposal

    public function test_the_ao_sees_the_finalized_figures_read_only_and_uploads_only_the_working_officers_list(): void
    {
        $proposal = $this->raise();
        $this->share($proposal, '75.00');
        $this->finalize(12, 7, 2, [['reason' => 'retirement', 'quota' => 'promotion', 'count' => 1]]);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $proposal))
            ->assertOk()
            ->assertSee('Vacancy position (Vacancy Management)')
            ->assertSee('data-admin-vacancy="promotion_vacancy">3<', false)
            ->assertSee('data-admin-vacancy="sanctioned">12<', false)
            // Step 1: the list is uploaded, never typed.
            ->assertSee('value="currently_working_us"', false)
            ->assertSee('id="working-list-missing"', false)
            ->assertDontSee('name="officers[', false);

        // The proposal's own page shows the same record in its S16 section.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))
            ->assertOk()
            ->assertSee('data-admin-vacancy="promotion_vacancy">3<', false);

        config(['dpc.manual_entry.working_officers' => true]);
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $proposal), [
            'officers' => [['sn' => 1, 'status' => 'vacant', 'reservation_category_id' => ReservationCategory::query()->value('id')]],
        ])->assertForbidden();

        $this->assertSame(0, $proposal->workingOfficers()->count());
    }

    public function test_without_a_finalized_record_the_proposal_says_so_and_cannot_be_submitted(): void
    {
        $proposal = $this->raise();

        // A draft is not authoritative either.
        app(VacancyMasterService::class)->openDraft($this->department, $this->to, self::YEAR, $this->admin);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $proposal))
            ->assertOk()
            ->assertSee('Vacancy information for the selected Department, Post and Year has not yet been finalized in Vacancy Management.');

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
