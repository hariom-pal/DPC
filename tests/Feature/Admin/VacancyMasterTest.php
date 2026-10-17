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

    /**
     * Open the draft and, unless told not to, record the year's sanctioned strength
     * (step 1) — 100, the figure these tests reason from.
     */
    private function openDraft(?int $sanctioned = 100): VacancyPosition
    {
        $this->actingAs($this->admin)->post($this->url('open'))->assertSessionHasNoErrors();

        if ($sanctioned !== null) {
            $this->saveSanctioned(['sanctioned_total' => (string) $sanctioned])->assertSessionHasNoErrors();
        }

        return VacancyPosition::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveSanctioned(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post($this->url('sanctioned-strength'), array_replace([
            'sanctioned_total' => '100',
            'order_number' => 'SE/SANC/2026/01',
            'order_date' => '2026-01-02',
            'document' => $this->pdf('sanction.pdf'),
        ], $overrides));
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

    /**
     * The page the redirect lands on, rendered with what the refused request flashed.
     *
     * The test client keeps no session cookie between requests, so the flashed bag is
     * handed on here — in the stored form, because with `session.serialization=json`
     * the store rebuilds the bag from that form and turns a live object into an empty
     * bag. A browser's session goes through exactly that round trip.
     */
    private function revisit(string $url): TestResponse
    {
        $errors = session('errors');
        $stored = $errors === null ? [] : array_map(
            fn ($bag): array => ['messages' => $bag->getMessages(), 'format' => $bag->getFormat()],
            $errors->getBags(),
        );

        return $this->actingAs($this->admin)->withSession([
            'errors' => $stored,
            '_old_input' => session('_old_input', []),
        ])->get($url);
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

    // ------------------------------------------- step 1: sanctioned strength

    /**
     * The sanctioned strength is entered for each Vacancy Year with its order — it can
     * change from year to year, so it is not read from the Department Post Mapping.
     */
    public function test_the_sanctioned_strength_is_entered_for_each_year_not_taken_from_the_post_mapping(): void
    {
        // The mapping says 100; the sanctioning order for 2026 says 80.
        $this->openDraft(80);
        $this->saveWorking(['working_total' => '75', 'working_promotion' => '40', 'working_direct' => '33', 'working_deputation' => '2'])->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertSame(80, $b['sanctioned']);
        $this->assertSame(5, $b['opening_vacancy'], 'Opening vacancies are reckoned from the year\'s own sanctioned strength.');
        $this->assertSame('SE/SANC/2026/01', $b['sanctioned_order']['order_number']);

        // A change to the mapping does not reach the year's figure.
        $this->principal->forceFill(['sanctioned_strength' => 500])->save();
        $this->assertSame(80, $this->breakdown()['sanctioned']);

        $this->assertDatabaseHas('activity_timelines', ['event' => 'vacancy_position.sanctioned_strength_recorded']);
    }

    public function test_each_year_needs_its_own_sanctioned_strength(): void
    {
        $this->openDraft(100);

        // 2027 starts with nothing, whatever 2026 or the mapping say.
        $next = app(VacancyMasterService::class)->openDraft($this->department, $this->principal, self::YEAR + 1, $this->admin);
        $b = app(VacancyMasterService::class)->breakdown($next->fresh());

        $this->assertNull($b['sanctioned']);
        $this->assertContains('sanctioned_not_entered', array_column($b['issues'], 'key'));
    }

    public function test_a_missing_sanctioned_strength_is_not_zero_and_blocks_finalization(): void
    {
        $position = $this->openDraft(null);
        $this->saveWorking()->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertNull($b['sanctioned']);
        $this->assertNull($b['opening_vacancy']);
        $this->assertContains('sanctioned_not_entered', array_column($b['issues'], 'key'));

        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasErrors('workflow');
        $this->assertSame(VacancyPositionStatus::Draft, $position->fresh()->status);
    }

    public function test_a_sanctioned_correction_needs_a_reason_and_keeps_the_history(): void
    {
        $position = $this->openDraft(100);

        $this->saveSanctioned(['sanctioned_total' => '110', 'order_number' => 'SE/SANC/2026/02'])->assertSessionHasErrors('correction_reason');
        $this->assertSame(100, $position->fresh()->sanctionedStrength->sanctioned_total);

        $this->saveSanctioned(['sanctioned_total' => '110', 'order_number' => 'SE/SANC/2026/02', 'correction_reason' => 'Ten posts created by the March order.'])
            ->assertSessionHasNoErrors();

        $position->refresh();
        $this->assertSame(110, $position->sanctionedStrength->sanctioned_total);
        $this->assertSame([100, 110], $position->sanctionedStrengthHistory->pluck('sanctioned_total')->all());
        $this->assertDatabaseHas('activity_timelines', ['event' => 'vacancy_position.sanctioned_strength_corrected']);
    }

    public function test_the_sanctioning_order_is_mandatory_and_downloadable(): void
    {
        $this->actingAs($this->admin)->post($this->url('open'))->assertSessionHasNoErrors();

        $this->saveSanctioned(['document' => null])->assertSessionHasErrors('document');
        $this->saveSanctioned(['document' => UploadedFile::fake()->create('sanction.docx', 10)])->assertSessionHasErrors('document');
        $this->saveSanctioned()->assertSessionHasNoErrors();

        $record = VacancyPosition::query()->latest('id')->firstOrFail()->sanctionedStrength;
        Storage::disk($record->disk)->assertExists($record->path);
        $this->assertSame(64, strlen($record->sha256));

        $this->actingAs($this->admin)
            ->get($this->url('sanctioned-strength.document', [$record]))
            ->assertOk();
    }

    public function test_a_sanctioned_strength_lowered_below_the_working_strength_must_be_confirmed(): void
    {
        $this->openDraft(100);
        $this->saveWorking()->assertSessionHasNoErrors();   // 75 working

        $this->saveSanctioned(['sanctioned_total' => '70', 'correction_reason' => 'Posts surrendered.'])->assertSessionHasNoErrors();

        $this->assertContains('working_exceeds_sanctioned', array_column($this->breakdown()['issues'], 'key'));
    }

    public function test_a_revision_carries_the_sanctioned_strength_forward(): void
    {
        $first = $this->openDraft(100);
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post($this->url('revise'), ['revision_reason' => 'Order re-read.'])->assertSessionHasNoErrors();

        $draft = VacancyPosition::query()->where('revision', 2)->firstOrFail();
        $this->assertSame(100, $draft->sanctionedStrength->sanctioned_total);
        $this->assertNotSame($first->sanctioned_strength_id, $draft->sanctioned_strength_id, 'The copy belongs to the new revision.');
    }

    // ---------------------------------------------------- the four steps

    public function test_each_step_has_its_own_screen(): void
    {
        $this->openDraft(null);

        $screen = fn (string $section) => $this->actingAs($this->admin)->get($this->url('show', ['section' => $section]))->assertOk();

        $screen('sanctioned')->assertSee(__('vacancymaster.sanctioned.heading', ['year' => self::YEAR]))
            ->assertSee('name="sanctioned_total"', false)
            ->assertDontSee('name="working_total"', false)
            ->assertDontSee('name="vacancy_count"', false);

        $screen('working')->assertSee('name="working_total"', false)
            ->assertDontSee('name="sanctioned_total"', false)
            ->assertDontSee('name="vacancy_count"', false)
            ->assertSee(__('vacancymaster.sanctioned.reference_missing', ['year' => self::YEAR]));

        $screen('vacancies')->assertSee('name="vacancy_count"', false)
            ->assertDontSee('name="working_total"', false);

        $screen('summary')->assertSee(__('vacancymaster.steps.missing_heading'))
            ->assertSee(__('vacancymaster.issues.sanctioned_not_entered', ['year' => self::YEAR]))
            ->assertSee(__('vacancymaster.finalize'));
    }

    public function test_the_screen_opens_on_the_first_step_still_to_do_and_moves_on_after_a_save(): void
    {
        $this->openDraft(null);

        $this->actingAs($this->admin)->get($this->url('show'))->assertOk()->assertSee('name="sanctioned_total"', false);

        $this->saveSanctioned()->assertRedirect($this->url('show', ['section' => 'working']));
        $this->actingAs($this->admin)->get($this->url('show'))->assertOk()->assertSee('name="working_total"', false);

        $this->saveWorking()->assertRedirect($this->url('show', ['section' => 'vacancies']));
        $this->actingAs($this->admin)->get($this->url('show'))->assertOk()->assertSee('name="vacancy_count"', false);

        // A refused save stays on its own step.
        $this->saveWorking(['working_total' => '70', 'correction_reason' => ''])->assertRedirect($this->url('show', ['section' => 'working']));
    }

    public function test_a_refused_save_shows_its_message_beside_the_field_it_is_about(): void
    {
        $this->openDraft();
        $here = $this->url('show', ['section' => 'working']);

        // A business rule (the split), refused by the service.
        $page = $this->from($here)->saveWorking(['working_promotion' => '41'])
            ->assertRedirect($here)
            ->assertSessionHasErrors('working_split');
        $html = $this->revisit($here)->assertOk()->getContent();
        $message = e(__('vacancymaster.errors.split_must_sum', ['total' => 75]));

        $this->assertSame(1, substr_count($html, $message), 'The message appears once, beside the field, not again at the top.');
        $this->assertMatchesRegularExpression('/split_help.*?|invalid-feedback d-block">'.preg_quote($message, '/').'/s', $html);
        $this->assertStringContainsString(e(__('vacancymaster.errors.fix_highlighted')), $html);
        $this->assertMatchesRegularExpression('/name="working_promotion"[^>]*autofocus/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*is-invalid[^"]*"[^>]*name="working_promotion"/', $html);
        $this->assertStringContainsString(e(__('vacancymaster.errors.document_reselect')), $html);

        // A field rule (the form request), named the way the form labels it.
        $this->from($here)->saveWorking(['order_number' => ''])->assertRedirect($here)->assertSessionHasErrors('order_number');
        $html = $this->revisit($here)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="order_number"[^>]*autofocus/', $html);
        $this->assertStringContainsString(__('vacancymaster.order_number'), $html);
    }

    public function test_an_entrys_message_stays_with_that_entry(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->addEntry()->assertSessionHasNoErrors();
        $entry = VacancyPositionEntry::query()->latest('id')->firstOrFail();
        $here = $this->url('show', ['section' => 'vacancies']);

        $this->actingAs($this->admin)->from($here)
            ->post($this->url('entries.withdraw', [$entry]), ['_entry' => (string) $entry->id, 'withdrawal_reason' => ''])
            ->assertRedirect($here)
            ->assertSessionHasErrors('withdrawal_reason', null, 'entry_'.$entry->id);

        $html = $this->revisit($here)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="withdrawal_reason"[^>]*is-invalid|is-invalid[^"]*"[^>]*name="withdrawal_reason"/', $html);
        $this->assertMatchesRegularExpression('/name="withdrawal_reason"[^>]*autofocus/', $html);

        // The add-entry form below is untouched: no red field, no PDF warning.
        $add = substr($html, (int) strpos($html, 'id="add-entry"'));
        $this->assertStringNotContainsString('is-invalid', $add);
        $this->assertStringNotContainsString(e(__('vacancymaster.errors.document_reselect')), $add);
    }

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

        $this->saveWorking(['working_total' => '74', 'working_direct' => '32'])->assertSessionHasErrors('correction_reason');

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
            ->assertSessionHasErrors('exceeds_sanctioned_acknowledged');

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

        $this->saveWorking(['working_promotion' => '41'])->assertSessionHasErrors('working_split');
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
        $this->addEntry(['effective_date' => '2027-01-01', 'order_number' => 'C'])->assertSessionHasErrors('effective_date');
        $this->addEntry(['effective_date' => '2025-12-31', 'order_number' => 'D'])->assertSessionHasErrors('effective_date');

        // An existing vacancy is one already open on 1 January.
        $this->addEntry(['kind' => 'existing', 'effective_date' => '2026-01-02', 'occupant_quota' => '', 'reconciliation' => '', 'order_number' => 'E'])
            ->assertSessionHasErrors('effective_date');

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
        $this->addEntry([...$higher, 'higher_post_id' => (string) $this->principal->id])->assertSessionHasErrors('higher_post_id');
        $this->addEntry($higher)->assertSessionHasNoErrors();
        $this->addEntry([...$higher, 'order_number' => 'SE/HP/2026/2'])->assertSessionHasErrors('higher_dpc_reference');

        $entry = VacancyPositionEntry::query()->sole();
        $this->assertSame(VacancyEntryEligibility::PendingVerification, $entry->eligibility);
        $this->assertSame(0, $this->breakdown()['anticipated_counted_promotion']);
        $this->assertContains('pending_entries', array_column($this->breakdown()['issues'], 'key'));

        // Verified on the order basis, which needs the order itself.
        $verify = $this->url('entries.verify', [$entry]);
        $this->actingAs($this->admin)->post($verify, ['decision' => 'eligible', 'basis' => 'promotion_order_issued', 'remarks' => 'Seen.'])
            ->assertSessionHasErrors('promotion_order_number', null, 'entry_'.$entry->id);
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

        // Step 1: the year's sanctioned strength, entered — the same figure the department states.
        $service->saveSanctionedStrength($position, [
            'sanctioned_total' => $sums['sanctioned'], 'order_number' => 'S', 'order_date' => '2026-01-02',
        ], $this->pdf(), $this->admin);

        $service->saveWorkingStrength($position->fresh(), [
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
