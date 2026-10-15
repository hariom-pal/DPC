<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyEntryEligibility;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The Vacancy Master: the CGPSC Administrator's year-wise vacancy position for a
 * post, inside Department Management.
 */
class VacancyMasterTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const YEAR = 2026;

    private User $admin;

    private User $ao;

    private User $ah;

    private Department $department;

    private DepartmentPost $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk', 'local'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $this->principal = $this->makePost($this->department, 'Principal', 100);
        $this->rule($this->principal, '50.00');
    }

    // ------------------------------------------------------------- fixtures

    private function makePost(Department $department, string $name, ?int $sanctioned, string $from = '2020-01-01'): DepartmentPost
    {
        $post = new DepartmentPost([
            'department_id' => $department->id,
            'name' => $name,
            'class_level' => 'II',
            'sanctioned_strength' => $sanctioned,
            'effective_from' => $from,
        ]);
        $post->status = MasterStatus::Active;
        $post->active_flag = 1;
        $post->save();

        return $post;
    }

    private function rule(DepartmentPost $post, string $promotion): RecruitmentRule
    {
        return RecruitmentRule::query()->create([
            'post_id' => $post->id,
            'version' => 1,
            'government_order_number' => 'GO/RR/'.$post->id,
            'direct_recruitment_pct' => 100 - (float) $promotion,
            'promotion_recruitment_pct' => $promotion,
            'effective_from' => '2020-01-01',
            'status' => MasterStatus::Active->value,
        ]);
    }

    private function pdf(string $name = 'order.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 50, 'application/pdf');
    }

    private function url(string $name, array $extra = [], ?DepartmentPost $post = null): string
    {
        return route("admin.departments.vacancy-master.{$name}", [$this->department, self::YEAR, $post ?? $this->principal, ...$extra]);
    }

    private function openDraft(): VacancyPosition
    {
        $this->actingAs($this->admin)->post($this->url('open'))->assertSessionHasNoErrors();

        return VacancyPosition::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveWorking(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post($this->url('working-strength'), array_replace([
            'working_total' => '75',
            'working_promotion' => '40',
            'working_direct' => '33',
            'working_deputation' => '2',
            'order_number' => 'SE/EST/2026/01',
            'order_date' => '2026-01-05',
            'document' => $this->pdf('working-strength.pdf'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addEntry(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post($this->url('entries.store'), array_replace([
            'kind' => 'anticipated',
            'reason' => 'retirement',
            'vacancy_count' => '5',
            'effective_date' => '2026-06-01',
            'occupant_quota' => 'promotion',
            'reconciliation' => 'counted_in_working',
            'order_number' => 'SE/RET/2026/06',
            'order_date' => '2026-01-10',
            'document' => $this->pdf('retirements.pdf'),
        ], $overrides));
    }

    private function breakdown(): array
    {
        $position = app(VacancyMasterService::class)->current($this->department->id, 'Principal', self::YEAR);

        return app(VacancyMasterService::class)->breakdown($position->fresh());
    }

    // ------------------------------------------------------------ navigation

    public function test_department_management_leads_to_the_departments_vacancy_master(): void
    {
        $other = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->makePost($other, 'Professor', 40);

        $this->actingAs($this->admin)->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee(route('admin.departments.vacancy-master.index', $this->department), false);

        $this->actingAs($this->admin)->get(route('admin.departments.vacancy-master.index', [$this->department, 'year' => self::YEAR]))
            ->assertOk()
            ->assertSee('School Education')
            ->assertSee('Principal')
            ->assertDontSee('Professor')
            ->assertSee(__('vacancymaster.not_entered'));
    }

    public function test_only_the_cgpsc_administrator_may_open_or_write(): void
    {
        foreach ([$this->ao, $this->ah] as $user) {
            $this->actingAs($user)->get(route('admin.departments.vacancy-master.index', $this->department))->assertForbidden();
            $this->actingAs($user)->get($this->url('show'))->assertForbidden();
            $this->actingAs($user)->post($this->url('open'))->assertForbidden();
        }

        $this->assertSame(0, VacancyPosition::query()->count());

        $this->openDraft();

        foreach ([$this->ao, $this->ah] as $user) {
            $this->actingAs($user)->post($this->url('working-strength'), [
                'working_total' => '75', 'order_number' => 'X', 'order_date' => '2026-01-05', 'document' => $this->pdf(),
            ])->assertForbidden();
            $this->actingAs($user)->post($this->url('finalize'))->assertForbidden();
        }

        $this->assertNull(VacancyPosition::query()->sole()->working_strength_id);
    }

    public function test_a_post_of_another_department_is_not_reachable_through_this_department(): void
    {
        $other = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $professor = $this->makePost($other, 'Professor', 40);

        $this->actingAs($this->admin)->get($this->url('show', post: $professor))->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('open', post: $professor))->assertNotFound();
    }

    public function test_vacancy_years_are_isolated(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $rows = app(VacancyMasterService::class)->postsFor($this->department, 2025);
        $this->assertNull($rows[0]['position']);

        $this->actingAs($this->admin)->get(route('admin.departments.vacancy-master.index', [$this->department, 'year' => 2025]))
            ->assertOk()
            ->assertSee(__('vacancymaster.list_status.not_started'));
    }

    // -------------------------------------------------------- working strength

    public function test_missing_working_strength_is_not_treated_as_zero(): void
    {
        $this->openDraft();
        $b = $this->breakdown();

        $this->assertNull($b['working']);
        $this->assertNull($b['opening_vacancy']);
        $this->assertFalse($b['complete']);
        $this->assertContains('working_not_entered', array_column($b['issues'], 'key'));
    }

    public function test_working_strength_is_recorded_with_its_order_and_document(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $working = $position->fresh()->workingStrength;
        $this->assertSame(75, $working->working_total);
        $this->assertSame('SE/EST/2026/01', $working->order_number);
        $this->assertSame(64, strlen($working->sha256));
        Storage::disk($working->disk)->assertExists($working->path);

        $this->assertSame(25, $this->breakdown()['opening_vacancy']);

        $this->actingAs($this->admin)
            ->get($this->url('working-strength.document', [$working]))
            ->assertOk();
    }

    public function test_a_correction_needs_a_reason_and_keeps_the_history(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->saveWorking(['working_total' => '74', 'working_direct' => '32'])->assertSessionHasErrors('workflow');

        $this->saveWorking(['working_total' => '74', 'working_direct' => '32', 'correction_reason' => 'One officer was relieved on 31-12-2025.'])
            ->assertSessionHasNoErrors();

        $history = $position->fresh()->workingStrengthHistory;
        $this->assertSame([75, 74], $history->pluck('working_total')->all());
        $this->assertSame('One officer was relieved on 31-12-2025.', $history->last()->correction_reason);
        $this->assertSame(26, $this->breakdown()['opening_vacancy']);
        $this->assertTrue(ActivityTimeline::query()->where('event', 'vacancy_position.working_strength_corrected')->exists());
    }

    public function test_a_supporting_document_is_mandatory(): void
    {
        $this->openDraft();

        $this->saveWorking(['document' => null])->assertSessionHasErrors('document');
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->addEntry(['document' => null])->assertSessionHasErrors('document');

        $this->assertSame(0, VacancyPositionEntry::query()->count());
    }

    public function test_working_strength_above_sanctioned_needs_an_explicit_resolution(): void
    {
        $this->openDraft();

        $this->saveWorking(['working_total' => '101', 'working_promotion' => '51', 'working_direct' => '48', 'working_deputation' => '2'])
            ->assertSessionHasErrors('workflow');

        $this->saveWorking([
            'working_total' => '101', 'working_promotion' => '51', 'working_direct' => '48', 'working_deputation' => '2',
            'exceeds_sanctioned_acknowledged' => '1', 'remarks' => 'Supernumerary post under court order.',
        ])->assertSessionHasNoErrors();

        // Recorded, but the position cannot be finalized while a quota is overfilled.
        $this->assertContains('quota_overfilled', array_column($this->breakdown()['issues'], 'key'));
    }

    public function test_the_split_must_add_up_to_the_working_strength(): void
    {
        $this->openDraft();

        $this->saveWorking(['working_promotion' => '41'])->assertSessionHasErrors('workflow');
        $this->saveWorking(['working_promotion' => '', 'working_direct' => '', 'working_deputation' => ''])->assertSessionHasNoErrors();

        $this->assertContains('working_split_missing', array_column($this->breakdown()['issues'], 'key'));
        $this->assertSame(25, $this->breakdown()['opening_vacancy']);
    }

    // ------------------------------------------------------------- calculation

    public function test_opening_vacancies_and_the_quota_split(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $b = $this->breakdown();

        $this->assertSame(100, $b['sanctioned']);
        $this->assertSame(25, $b['opening_vacancy']);
        $this->assertSame(50, $b['opening_quota']['promotion_chairs']);
        $this->assertSame(50, $b['opening_quota']['direct_chairs']);
        // 50 − 40 promotion officers; 50 − 33 direct recruits. The 2 on deputation hold neither quota.
        $this->assertSame(10, $b['opening_quota']['promotion_vacancy']);
        $this->assertSame(17, $b['opening_quota']['direct_vacancy']);
        $this->assertSame(10, $b['final_quota']['promotion_vacancy']);
        $this->assertTrue($b['complete']);
    }

    public function test_an_eligible_retirement_adds_to_its_own_quota_only(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->addEntry()->assertSessionHasNoErrors();
        $this->addEntry(['occupant_quota' => 'direct', 'vacancy_count' => '2', 'order_number' => 'SE/RET/2026/07'])->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertSame(5, $b['anticipated_counted_promotion']);
        $this->assertSame(2, $b['anticipated_counted_direct']);
        $this->assertSame(15, $b['final_quota']['promotion_vacancy']);
        $this->assertSame(19, $b['final_quota']['direct_vacancy']);
        $this->assertSame(25, $b['opening_vacancy'], 'The opening figure itself is not changed by anticipated vacancies.');
    }

    public function test_a_vacancy_already_inside_the_opening_figure_is_not_counted_twice(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->addEntry(['reconciliation' => 'already_in_opening'])->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertSame(0, $b['anticipated_counted_promotion']);
        $this->assertSame(5, $b['anticipated_already_in_opening']);
        $this->assertSame(10, $b['final_quota']['promotion_vacancy']);
    }

    public function test_double_counting_safeguards(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        // The same order for the same vacancy cannot be entered twice.
        $this->addEntry()->assertSessionHasNoErrors();
        $this->addEntry()->assertSessionHasErrors('workflow');

        // More counted than officers of that quota were working is refused at finalization.
        $this->addEntry(['vacancy_count' => '36', 'order_number' => 'SE/RET/2026/09'])->assertSessionHasNoErrors();
        $this->assertContains('anticipated_exceed_working', array_column($this->breakdown()['issues'], 'key'));

        // Existing-vacancy entries may explain the opening figure, never exceed it.
        $this->addEntry(['kind' => 'existing', 'effective_date' => '2025-09-30', 'vacancy_count' => '26',
            'occupant_quota' => '', 'reconciliation' => '', 'order_number' => 'SE/OLD/2025'])->assertSessionHasNoErrors();
        $this->assertContains('existing_exceed_opening', array_column($this->breakdown()['issues'], 'key'));
    }

    public function test_rounding_follows_rule_one_three_and_stays_switchable(): void
    {
        $engine = app(VacancyEngine::class);

        // 5 × 50% = 2.5: half up gives promotion the post (DR .5 dropped, rule 1.3).
        $this->assertSame(3, $engine->quotaPosition(5, '50.00', 0, 0)['promotion_chairs']);
        $this->assertSame(2, $engine->quotaPosition(5, '50.00', 0, 0)['direct_chairs']);
        $this->assertSame(2, $engine->quotaPosition(7, '33.33', 0, 0)['promotion_chairs']);

        config(['dpc.vacancy.promotion_rounding' => 'floor']);
        $this->assertSame(2, $engine->quotaPosition(5, '50.00', 0, 0)['promotion_chairs']);
    }

    // ----------------------------------------------------------- year boundary

    public function test_the_vacancy_year_is_first_january_to_thirty_first_december_inclusive(): void
    {
        [$from, $to] = app(VacancyEngine::class)->periodForYear(self::YEAR);
        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-12-31', $to->toDateString());

        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->addEntry(['effective_date' => '2026-01-01', 'order_number' => 'A'])->assertSessionHasNoErrors();
        $this->addEntry(['effective_date' => '2026-12-31', 'order_number' => 'B'])->assertSessionHasNoErrors();
        $this->addEntry(['effective_date' => '2027-01-01', 'order_number' => 'C'])->assertSessionHasErrors('workflow');
        $this->addEntry(['effective_date' => '2025-12-31', 'order_number' => 'D'])->assertSessionHasErrors('workflow');

        // An existing vacancy is one already open on 1 January.
        $this->addEntry(['kind' => 'existing', 'effective_date' => '2026-01-02', 'occupant_quota' => '', 'reconciliation' => '', 'order_number' => 'E'])
            ->assertSessionHasErrors('workflow');

        $this->assertSame(2, VacancyPositionEntry::query()->count());
    }

    // ---------------------------------------------------------------- reasons

    public function test_a_higher_post_promotion_waits_for_verification_and_cites_one_dpc(): void
    {
        $lecturer = $this->makePost($this->department, 'Joint Director', 10);
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $higher = ['reason' => 'higher_post_promotion', 'vacancy_count' => '3', 'higher_post_id' => (string) $lecturer->id,
            'higher_dpc_reference' => 'DPC-2026-SE-0007', 'order_number' => 'SE/HP/2026/1'];

        $this->addEntry([...$higher, 'higher_dpc_reference' => ''])->assertSessionHasErrors('higher_dpc_reference');
        $this->addEntry([...$higher, 'higher_post_id' => (string) $this->principal->id])->assertSessionHasErrors('workflow');
        $this->addEntry($higher)->assertSessionHasNoErrors();
        $this->addEntry([...$higher, 'order_number' => 'SE/HP/2026/2'])->assertSessionHasErrors('workflow');

        $entry = VacancyPositionEntry::query()->sole();
        $this->assertSame(VacancyEntryEligibility::PendingVerification, $entry->eligibility);
        $this->assertSame(0, $this->breakdown()['anticipated_counted_promotion']);
        $this->assertContains('pending_entries', array_column($this->breakdown()['issues'], 'key'));

        // Verified on the order basis, which needs the order itself.
        $verify = $this->url('entries.verify', [$entry]);
        $this->actingAs($this->admin)->post($verify, ['decision' => 'eligible', 'basis' => 'promotion_order_issued', 'remarks' => 'Seen.'])
            ->assertSessionHasErrors('workflow');
        $this->actingAs($this->admin)->post($verify, [
            'decision' => 'eligible', 'basis' => 'promotion_order_issued', 'remarks' => 'Order seen.',
            'promotion_order_number' => 'SE/PROM/2026/3', 'promotion_order_date' => '2026-02-01',
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(VacancyEntryEligibility::Eligible, $entry->eligibility);
        $this->assertSame($this->admin->id, $entry->verified_by);
        $this->assertSame(3, $this->breakdown()['anticipated_counted_promotion']);
    }

    public function test_deputation_follows_rule_two_three(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $base = ['reason' => 'deputation', 'vacancy_count' => '1', 'effective_date' => '2026-03-01'];

        $this->addEntry([...$base, 'order_number' => 'D1', 'against_sanctioned_post' => '1', 'deputation_to' => '2027-06-30'])->assertSessionHasNoErrors();
        $this->addEntry([...$base, 'order_number' => 'D2', 'against_sanctioned_post' => '1', 'deputation_expected_months' => '6'])->assertSessionHasNoErrors();
        $this->addEntry([...$base, 'order_number' => 'D3'])->assertSessionHasNoErrors();
        $this->addEntry([...$base, 'order_number' => 'D4', 'against_sanctioned_post' => '0', 'deputation_expected_months' => '24'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['eligible', 'not_eligible', 'pending_verification', 'pending_verification'],
            VacancyPositionEntry::query()->orderBy('id')->get()->map(fn ($e) => $e->eligibility->value)->all(),
        );
        $this->assertSame(1, $this->breakdown()['anticipated_counted_promotion']);
    }

    public function test_a_reason_without_a_rule_is_never_counted_automatically(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->addEntry(['reason' => 'death', 'vacancy_count' => '1', 'order_number' => 'X1'])->assertSessionHasNoErrors();
        $this->addEntry(['reason' => 'other', 'vacancy_count' => '1', 'order_number' => 'X2'])->assertSessionHasNoErrors();
        $this->addEntry(['reason' => 'direct_recruitment', 'order_number' => 'X3'])->assertSessionHasErrors('reason');

        $b = $this->breakdown();
        $this->assertSame(0, $b['anticipated_counted_promotion']);
        $this->assertSame(2, $b['anticipated_pending']);
        $this->assertFalse($b['complete']);

        // A decision either way resolves the entry; not eligible never counts.
        $entry = VacancyPositionEntry::query()->where('reason', 'other')->sole();
        $this->actingAs($this->admin)->post($this->url('entries.verify', [$entry]), ['decision' => 'not_eligible', 'remarks' => 'Not an approved cause.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->breakdown()['anticipated_pending']);
    }

    // ---------------------------------------------------- finalize & history

    public function test_finalization_requires_a_complete_position(): void
    {
        $position = $this->openDraft();

        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasErrors('workflow');
        $this->assertSame(VacancyPositionStatus::Draft, $position->fresh()->status);

        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $position->refresh();
        $this->assertSame(VacancyPositionStatus::Finalized, $position->status);
        $this->assertSame(100, $position->sanctioned_strength);
        $this->assertSame(25, $position->opening_vacancy);
        $this->assertSame(10, $position->promotion_vacancy);
        $this->assertSame(17, $position->direct_vacancy);
        $this->assertSame('50.00', $position->promotion_percentage);
        $this->assertSame('round', $position->rounding_rule);

        // Frozen: no further edit reaches it.
        $this->saveWorking(['working_total' => '70', 'working_promotion' => '35', 'correction_reason' => 'late'])->assertSessionHasErrors('workflow');
        $this->addEntry()->assertSessionHasErrors('workflow');
        $this->assertSame(75, $position->fresh()->workingStrength->working_total);
    }

    public function test_a_finalized_position_keeps_its_snapshot_when_the_master_changes(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->principal->forceFill(['sanctioned_strength' => 120])->save();
        RecruitmentRule::query()->update(['promotion_recruitment_pct' => '70.00']);

        $b = app(VacancyMasterService::class)->breakdown($position->fresh());
        $this->assertSame(100, $b['sanctioned']);
        $this->assertSame('50.00', $b['promotion_percentage']);
        $this->assertSame(10, $b['final_quota']['promotion_vacancy']);
    }

    public function test_a_correction_after_finalization_is_a_new_revision(): void
    {
        $first = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->addEntry()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post($this->url('revise'), [])->assertSessionHasErrors('revision_reason');
        $this->actingAs($this->admin)->post($this->url('revise'), ['revision_reason' => 'Retirement order corrected.'])->assertSessionHasNoErrors();

        $second = app(VacancyMasterService::class)->current($this->department->id, 'Principal', self::YEAR);
        $this->assertSame(2, $second->revision);
        $this->assertTrue($second->isDraft());
        $this->assertSame(75, $second->workingStrength->working_total, 'The working strength is carried forward.');
        $this->assertSame(1, $second->entries()->count(), 'Active entries are carried forward.');

        // Until the revision is finalized the first stays the authoritative one.
        $this->assertSame($first->id, app(VacancyMasterService::class)->finalized($this->department->id, 'Principal', self::YEAR)->id);

        $entry = $second->entries()->sole();
        $this->actingAs($this->admin)->post($this->url('entries.withdraw', [$entry]), ['withdrawal_reason' => 'Order cancelled.'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->assertSame(VacancyPositionStatus::Superseded, $first->fresh()->status);
        $this->assertSame(15, $first->fresh()->promotion_vacancy, 'The first revision keeps its own figures.');
        $this->assertSame(10, $second->fresh()->promotion_vacancy);
        $this->assertSame($second->id, app(VacancyMasterService::class)->finalized($this->department->id, 'Principal', self::YEAR)->id);
        $this->assertSame(VacancyPositionEntry::STATUS_WITHDRAWN, $entry->fresh()->status);

        $this->actingAs($this->admin)->get($this->url('show'))
            ->assertOk()
            ->assertSee('Retirement order corrected.');
    }

    // ------------------------------------------------- seniority & proposals

    public function test_the_seniority_reference_date_is_first_april_of_the_previous_year(): void
    {
        $this->assertSame('2025-04-01', app(VacancyMasterService::class)->seniorityReferenceDate(2026)->toDateString());

        $this->actingAs($this->admin)->get($this->url('show'))
            ->assertOk()
            ->assertSee(__('vacancymaster.seniority_reference', ['date' => '01-04-2025']));
    }

    public function test_a_dpc_proposal_is_reconciled_against_the_finalized_position_only(): void
    {
        $from = $this->makePost($this->department, 'Lecturer', 200);
        $to = $this->makePost($this->department, 'Deputy Director', null, '2026-01-01');
        $proposal = $this->raiseDpc($this->ao, $from, $to);
        $this->makeProposalSubmittable($proposal, $this->ao);

        $completeness = app(DpcProposalCompletenessService::class);
        $keys = fn (): array => array_column($completeness->checklist($proposal->fresh()), 'key');

        // No position: the proposal works as before.
        $this->assertNotContains('vacancy_master', $keys());

        $sums = app(VacancyCalculationService::class)->summary($proposal->fresh());
        $service = app(VacancyMasterService::class);
        $position = $service->openDraft($this->department, $to->fresh(), self::YEAR, $this->admin);

        // A draft is never authoritative.
        $this->assertNotContains('vacancy_master', $keys());

        $service->saveWorkingStrength($position, [
            'working_total' => $sums['working'] + 1, 'working_promotion' => $sums['working'] + 1, 'working_direct' => 0, 'working_deputation' => 0,
            'order_number' => 'O', 'order_date' => '2026-01-02',
        ], $this->pdf(), $this->admin);
        $service->finalize($position->fresh(), $this->admin);

        $section = collect($completeness->checklist($proposal->fresh()))->firstWhere('key', 'vacancy_master');
        $this->assertFalse($section['complete']);
        $this->assertStringContainsString('Working officers differ', $section['detail']);

        // Corrected by revision to agree with the chart: the proposal may now go.
        $draft = $service->revise($position->fresh(), 'Count corrected.', $this->admin);
        $service->saveWorkingStrength($draft, [
            'working_total' => $sums['working'], 'working_promotion' => $sums['working'], 'working_direct' => 0, 'working_deputation' => 0,
            'order_number' => 'O2', 'order_date' => '2026-01-02', 'correction_reason' => 'One officer fewer.',
        ], $this->pdf(), $this->admin);
        $service->finalize($draft->fresh(), $this->admin);

        $this->assertTrue(collect($completeness->checklist($proposal->fresh()))->firstWhere('key', 'vacancy_master')['complete']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal))->assertSessionHasNoErrors();
        $this->assertSame($draft->id, DpcProposal::query()->find($proposal->id)->vacancy_position_id);
    }

    public function test_the_master_can_be_made_mandatory_for_submission(): void
    {
        $from = $this->makePost($this->department, 'Lecturer', 200);
        $to = $this->makePost($this->department, 'Deputy Director', null);
        $proposal = $this->raiseDpc($this->ao, $from, $to);
        $this->makeProposalSubmittable($proposal, $this->ao);

        config(['dpc.vacancy_master.required_for_submission' => true]);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $proposal))->assertSessionHasErrors('workflow');
        $this->assertSame('draft', $proposal->fresh()->status->value);
    }
}
