<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
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
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Vacancy Management: the department's record of a post's vacancies for the year of
 * an approved Pre-DPC (2026-10-30): A clear + B deputation + C anticipated, and the
 * Pre-DPC's share of that total.
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
        $this->feeder = $this->makePost($this->department, 'Vice Principal', 200);
        $this->preDpc = $this->approvedPreDpc($this->principal, self::YEAR);
    }

    private DepartmentPost $feeder;

    private PreDpcProposal $preDpc;

    /**
     * The approved Pre-DPC a record is kept for (2026-10-30), at a 50 % promotion share.
     */
    private function approvedPreDpc(DepartmentPost $post, int $year, string $promotion = '50.00'): PreDpcProposal
    {
        $order = $this->sanctioningPreDpc($this->feeder, $post, $year);
        $order->forceFill(['promotion_recruitment_pct' => $promotion, 'direct_recruitment_pct' => 100 - (float) $promotion])->save();

        return $order;
    }

    // ------------------------------------------------------------- fixtures

    private function makePost(Department $department, string $name, ?int $sanctioned, string $from = '2020-01-01'): DepartmentPost
    {
        $post = new DepartmentPost([
            'department_id' => $department->id,
            'name' => $name,
            'class_level' => 'II',
            'effective_from' => $from,
        ]);
        $post->status = MasterStatus::Active;
        $post->active_flag = 1;
        // The retired mapping column, written directly: these tests prove the
        // Vacancy Master never reads it.
        $post->forceFill(['sanctioned_strength' => $sanctioned]);
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
        return route("vacancy-records.{$name}", [$this->department, self::YEAR, $post ?? $this->principal, ...$extra]);
    }

    /**
     * Open the draft and, unless told not to, record the year's sanctioned strength
     * (step 1) — 100, the figure these tests reason from.
     */
    private function openDraft(?int $sanctioned = 100): VacancyPosition
    {
        $this->actingAs($this->ao)->post($this->url('open'))->assertSessionHasNoErrors();

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
        return $this->actingAs($this->ao)->post($this->url('sanctioned-strength'), array_replace([
            'sanctioned_total' => '100',
            'order_number' => 'SE/SANC/2026/01',
            'order_date' => '2026-01-02',
            'document' => $this->pdf('sanction.pdf'),
        ], $overrides));
    }

    /**
     * Step 2: our own officers on the post — 75 unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function saveWorking(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->ao)->post($this->url('working-strength'), array_replace([
            'working_total' => '75',
            'order_number' => 'SE/EST/2026/01',
            'order_date' => '2026-01-05',
            'document' => $this->pdf('working-strength.pdf'),
        ], $overrides));
    }

    /**
     * Step 3: one row. A retirement on 1 June unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function addEntry(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->ao)->post($this->url('entries.store'), array_replace([
            'kind' => 'anticipated',
            'reason' => 'retirement',
            'officer_name' => 'R. Sharma',
            'effective_date' => '2026-06-01',
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

        return $this->actingAs($this->ao)->withSession([
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

        // The Administrator still finds every department's records, read-only.
        $this->actingAs($this->admin)->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee(route('admin.departments.vacancy-master.index', $this->department), false);

        $this->actingAs($this->admin)->get(route('admin.departments.vacancy-master.index', [$this->department, 'year' => self::YEAR]))
            ->assertOk()
            ->assertSee('School Education')
            ->assertSee('Principal')
            ->assertDontSee('Professor')
            ->assertSee(__('vacancymaster.not_entered'));

        // The department's own officer reaches them from Vacancy Management.
        $this->actingAs($this->ao)->get(route('vacancy-records.index', [$this->department, 'year' => self::YEAR]))
            ->assertOk()
            ->assertSee('Principal')
            ->assertDontSee('Professor');
    }

    /**
     * Since 2026-10-19 the department's Authorized Officer keeps and finalizes the
     * records; the Administrative Head and the CGPSC Administrator read them; another
     * department's officer does neither.
     */
    public function test_only_the_departments_authorized_officer_may_open_or_write(): void
    {
        $other = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $outsider = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($outsider)->get(route('vacancy-records.index', $this->department))->assertForbidden();
        $this->actingAs($outsider)->get($this->url('show'))->assertForbidden();
        $this->actingAs($outsider)->post($this->url('open'))->assertForbidden();
        $this->actingAs($this->ah)->post($this->url('open'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.departments.vacancy-master.open', [$this->department, self::YEAR, $this->principal]))->assertForbidden();
        $this->actingAs($outsider)->post(route('vacancy-management.pre-dpc', $this->preDpc))->assertForbidden();

        $this->assertSame(0, VacancyPosition::query()->count());

        $this->openDraft();

        foreach ([$this->ah, $outsider] as $user) {
            $this->actingAs($user)->post($this->url('working-strength'), ['working_total' => '75'])->assertForbidden();
            $this->actingAs($user)->post($this->url('entries.store'), ['kind' => 'anticipated', 'reason' => 'retirement', 'officer_name' => 'X', 'effective_date' => '2026-05-01'])->assertForbidden();
            $this->actingAs($user)->post($this->url('finalize'))->assertForbidden();
        }

        $this->actingAs($this->admin)->post(route('admin.departments.vacancy-master.finalize', [$this->department, self::YEAR, $this->principal]))->assertForbidden();
        $this->assertNull(VacancyPosition::query()->sole()->working_strength_id);

        // Reading: the Administrative Head here, the Administrator there — without forms.
        $this->actingAs($this->ah)->get($this->url('show'))->assertOk()->assertSee('id="vacancy-read-only"', false);
        $this->actingAs($this->admin)->get(route('admin.departments.vacancy-master.show', [$this->department, self::YEAR, $this->principal, 'section' => 'sanctioned']))
            ->assertOk()
            ->assertSee('id="vacancy-read-only"', false)
            ->assertDontSee('name="sanctioned_total"', false);
        $this->actingAs($this->ao)->get($this->url('show', ['section' => 'sanctioned']))
            ->assertOk()
            ->assertSee('name="sanctioned_total"', false);
    }

    public function test_a_post_of_another_department_is_not_reachable_through_this_department(): void
    {
        $other = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $professor = $this->makePost($other, 'Professor', 40);

        $this->actingAs($this->ao)->get($this->url('show', post: $professor))->assertNotFound();
        $this->actingAs($this->ao)->post($this->url('open', post: $professor))->assertNotFound();
    }

    public function test_vacancy_years_are_isolated(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $rows = app(VacancyMasterService::class)->postsFor($this->department, 2025);
        $this->assertNull(collect($rows)->firstWhere('name', 'Principal')['position']);

        $this->actingAs($this->ao)->get(route('vacancy-records.index', [$this->department, 'year' => 2025]))
            ->assertOk()
            ->assertSee(__('vacancymaster.list_status.not_started'));
    }

    // ------------------------------------------------------ one per Pre-DPC

    /**
     * A record is kept for an approved Pre-DPC: its post and year are the record's,
     * and so is the promotion share. Without one there is nothing to open.
     */
    public function test_a_record_opens_only_from_an_approved_pre_dpc_of_the_post_and_year(): void
    {
        $this->actingAs($this->ao)->post($this->url('open', post: $this->feeder))->assertSessionHasErrors('workflow');
        $this->assertSame(0, VacancyPosition::query()->count());

        // The button on the approved Pre-DPC, and on the listing.
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $this->preDpc))
            ->assertOk()
            ->assertSee('id="pre-dpc-vacancy"', false)
            ->assertSee(__('vacancymaster.pm.proceed', ['post' => 'Principal']));
        $this->actingAs($this->ao)->get(route('pre-dpc.index'))->assertOk()->assertSee('data-pre-dpc-vacancy', false);

        $this->actingAs($this->ao)->post(route('vacancy-management.pre-dpc', $this->preDpc))
            ->assertRedirect($this->url('show', ['section' => 'sanctioned']));

        $position = VacancyPosition::query()->sole();
        $this->assertSame($this->preDpc->id, $position->pre_dpc_proposal_id);
        $this->assertSame('Principal', $position->post_name);
        $this->assertSame(self::YEAR, $position->vacancy_year);

        // Again: the same record, not a second one.
        $this->actingAs($this->ao)->post(route('vacancy-management.pre-dpc', $this->preDpc))->assertRedirect();
        $this->assertSame(1, VacancyPosition::query()->count());

        $this->actingAs($this->ao)->get($this->url('show'))->assertOk()->assertSee('id="vacancy-pre-dpc"', false)->assertSee($this->preDpc->pre_dpc_number);

        // The Administrative Head reads it; the button does not open one for them.
        $this->actingAs($this->ah)->post(route('vacancy-management.pre-dpc', $this->preDpc))->assertRedirect();
        $this->assertSame(1, VacancyPosition::query()->count());
    }

    public function test_a_pre_dpc_not_yet_approved_has_no_vacancy_button(): void
    {
        $this->preDpc->forceFill(['status' => 'submitted', 'active_flag' => null])->save();

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $this->preDpc))->assertOk()->assertDontSee('id="pre-dpc-vacancy"', false);
        $this->actingAs($this->ao)->post(route('vacancy-management.pre-dpc', $this->preDpc))->assertForbidden();
    }

    // ------------------------------------------- step 1: sanctioned strength

    /**
     * The sanctioned strength is entered for each Vacancy Year with its order — it can
     * change from year to year, so it is not read from the Department Post Mapping.
     */
    public function test_the_sanctioned_strength_is_entered_for_each_year_not_taken_from_the_post_mapping(): void
    {
        // The mapping says 100; the sanctioning order for 2026 says 80.
        $this->openDraft(80);
        $this->saveWorking()->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertSame(80, $b['sanctioned']);
        $this->assertSame(5, $b['clear_vacancy'], 'The clear vacancy is reckoned from the year\'s own sanctioned strength.');
        $this->assertSame('SE/SANC/2026/01', $b['sanctioned_order']['order_number']);

        // A change to the mapping does not reach the year's figure.
        $this->principal->forceFill(['sanctioned_strength' => 500])->save();
        $this->assertSame(80, $this->breakdown()['sanctioned']);

        $this->assertDatabaseHas('activity_timelines', ['event' => 'vacancy_position.sanctioned_strength_recorded']);
    }

    public function test_each_year_needs_its_own_sanctioned_strength(): void
    {
        $this->openDraft(100);
        $this->approvedPreDpc($this->principal, self::YEAR + 1);

        // 2027 starts with nothing, whatever 2026 or the mapping say.
        $next = app(VacancyMasterService::class)->openDraft($this->department, $this->principal, self::YEAR + 1, $this->ao);
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
        $this->assertNull($b['clear_vacancy']);
        $this->assertNull($b['total_vacancy']);
        $this->assertContains('sanctioned_not_entered', array_column($b['issues'], 'key'));

        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasErrors('workflow');
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
        $this->actingAs($this->ao)->post($this->url('open'))->assertSessionHasNoErrors();

        $this->saveSanctioned(['document' => null])->assertSessionHasErrors('document');
        $this->saveSanctioned(['document' => UploadedFile::fake()->create('sanction.docx', 10)])->assertSessionHasErrors('document');
        $this->saveSanctioned()->assertSessionHasNoErrors();

        $record = VacancyPosition::query()->latest('id')->firstOrFail()->sanctionedStrength;
        Storage::disk($record->disk)->assertExists($record->path);
        $this->assertSame(64, strlen($record->sha256));

        $this->actingAs($this->ao)
            ->get($this->url('sanctioned-strength.document', [$record]))
            ->assertOk();
    }

    public function test_the_sanctioned_strength_cannot_be_lowered_below_what_is_counted(): void
    {
        $position = $this->openDraft(100);
        $this->saveWorking()->assertSessionHasNoErrors();   // 75 working

        $this->saveSanctioned(['sanctioned_total' => '70', 'correction_reason' => 'Posts surrendered.'])->assertSessionHasErrors('sanctioned_total');

        $this->assertSame(100, $position->fresh()->sanctionedStrength->sanctioned_total, 'The refused correction is not kept.');
        $this->assertSame(1, $position->fresh()->sanctionedStrengthHistory()->count());
    }

    public function test_a_revision_carries_the_sanctioned_strength_forward(): void
    {
        $first = $this->openDraft(100);
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->actingAs($this->ao)->post($this->url('revise'), ['revision_reason' => 'Order re-read.'])->assertSessionHasNoErrors();

        $draft = VacancyPosition::query()->where('revision', 2)->firstOrFail();
        $this->assertSame(100, $draft->sanctionedStrength->sanctioned_total);
        $this->assertSame($this->preDpc->id, $draft->pre_dpc_proposal_id, 'The revision belongs to the same Pre-DPC.');
        $this->assertNotSame($first->sanctioned_strength_id, $draft->sanctioned_strength_id, 'The copy belongs to the new revision.');
    }

    // ---------------------------------------------------- the three steps

    public function test_each_step_has_its_own_screen(): void
    {
        $this->openDraft(null);

        $screen = fn (string $section) => $this->actingAs($this->ao)->get($this->url('show', ['section' => $section]))->assertOk();

        // Sanctioned and working strength on one screen, with one order (2026-10-31).
        $screen('sanctioned')->assertSee(__('vacancymaster.pm.strength_heading', ['year' => self::YEAR]))
            ->assertSee('name="sanctioned_total"', false)
            ->assertSee('name="working_total"', false)
            ->assertSee('id="strength-form"', false)
            ->assertDontSee('id="vacancy-b1"', false)
            ->assertSee(__('vacancymaster.steps.strength'))
            ->assertDontSee('section=working', false);

        // The old step key lands on the same screen.
        $screen('working')->assertSee('id="strength-form"', false);

        $screen('vacancies')->assertSee('id="vacancy-b1"', false)
            ->assertSee('id="vacancy-b2"', false)
            ->assertSee('id="vacancy-c"', false)
            ->assertSee(__('vacancymaster.errors.figures_first'))
            ->assertDontSee('name="working_total"', false);

        $screen('summary')->assertSee(__('vacancymaster.steps.missing_heading'))
            ->assertSee(__('vacancymaster.issues.sanctioned_not_entered', ['year' => self::YEAR]))
            ->assertSee(__('vacancymaster.finalize'));
    }

    // ------------------------------- step 1: sanctioned and working together

    private function saveStrength(array $data): TestResponse
    {
        return $this->actingAs($this->ao)->from($this->url('show', ['section' => 'sanctioned']))->post($this->url('strength'), $data);
    }

    private function position(): VacancyPosition
    {
        return app(VacancyMasterService::class)->current($this->department->id, 'Principal', self::YEAR)->fresh();
    }

    public function test_both_figures_are_saved_once_with_one_order_and_a_working_change_needs_no_new_document(): void
    {
        $this->openDraft(null);

        $this->saveStrength([
            'sanctioned_total' => '10', 'working_total' => '6',
            'order_number' => 'SE/SANC/1', 'order_date' => '2026-01-02', 'document' => $this->pdf('sanction.pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect($this->url('show', ['section' => 'vacancies']));

        $position = $this->position();
        $this->assertSame(10, $position->sanctionedStrength->sanctioned_total);
        $this->assertSame(6, $position->workingStrength->working_total);
        $this->assertSame('SE/SANC/1', $position->workingStrength->order_number, 'Certified by the same order.');
        $this->assertNull($position->workingStrength->path, 'One PDF, kept once.');

        // Later only the working figure changes: a reason, no order, no PDF.
        $this->saveStrength(['sanctioned_total' => '10', 'working_total' => '7'])->assertSessionHasErrors('correction_reason');
        $this->saveStrength(['sanctioned_total' => '10', 'working_total' => '7', 'correction_reason' => 'One more officer joined.'])->assertSessionHasNoErrors();

        $position = $this->position();
        $this->assertSame(7, $position->workingStrength->working_total);
        $this->assertCount(1, $position->sanctionedStrengthHistory, 'The sanctioned figure did not change, so it is not written again.');

        // A changed sanctioned figure needs its order again.
        $this->saveStrength(['sanctioned_total' => '12', 'working_total' => '7', 'correction_reason' => 'New order.'])->assertSessionHasErrors('order_number');
        $this->saveStrength(['sanctioned_total' => '12', 'working_total' => '7', 'order_number' => 'SE/SANC/2', 'order_date' => '2026-02-01', 'correction_reason' => 'New order.'])
            ->assertSessionHasErrors('document');

        // Nothing changed: said so, nothing written.
        $this->saveStrength(['sanctioned_total' => '10', 'working_total' => '7', 'correction_reason' => 'x'])->assertSessionHasErrors('sanctioned_total');
        $this->assertCount(2, $this->position()->workingStrengthHistory);
    }

    public function test_lowering_both_figures_together_is_judged_on_the_new_pair_and_a_refusal_writes_nothing(): void
    {
        $this->openDraft(null);
        $this->saveStrength([
            'sanctioned_total' => '10', 'working_total' => '8',
            'order_number' => 'SE/SANC/1', 'order_date' => '2026-01-02', 'document' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $disk = Storage::disk((string) config('dpc.documents.disk', 'local'));
        $files = count($disk->allFiles());

        // Sanctioned below the working figure: refused, and the new PDF is not left behind.
        $this->saveStrength(['sanctioned_total' => '6', 'working_total' => '8', 'order_number' => 'SE/SANC/2', 'order_date' => '2026-02-01', 'document' => $this->pdf(), 'correction_reason' => 'Posts reduced.'])
            ->assertSessionHasErrors();
        $this->assertSame(10, $this->position()->sanctionedStrength->sanctioned_total);
        $this->assertSame($files, count($disk->allFiles()));

        // Both lowered together: allowed.
        $this->saveStrength(['sanctioned_total' => '6', 'working_total' => '5', 'order_number' => 'SE/SANC/2', 'order_date' => '2026-02-01', 'document' => $this->pdf(), 'correction_reason' => 'Posts reduced.'])
            ->assertSessionHasNoErrors();
        $position = $this->position();
        $this->assertSame([6, 5], [$position->sanctionedStrength->sanctioned_total, $position->workingStrength->working_total]);
    }

    public function test_after_a_row_is_added_the_screen_returns_to_its_part(): void
    {
        $this->openDraft(null);
        $this->saveStrength([
            'sanctioned_total' => '10', 'working_total' => '6',
            'order_number' => 'SE/SANC/1', 'order_date' => '2026-01-02', 'document' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->ao)->post($this->url('entries.store'), ['kind' => 'deputation', 'officer_name' => 'C. Patel', 'deputation_from' => '2025-03-01', 'promotion_made' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('show', ['section' => 'vacancies', 'part' => 'b2']));

        $html = $this->actingAs($this->ao)->get($this->url('show', ['section' => 'vacancies', 'part' => 'b2']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="tab-pane show active" id="pane-b2"/', $html);
        $this->assertMatchesRegularExpression('/class="tab-pane" id="pane-b1"/', $html);
        $this->assertStringContainsString('aria-selected="true"', substr($html, (int) strpos($html, 'id="tab-b2"'), 400));
    }

    public function test_the_screen_opens_on_the_first_step_still_to_do_and_moves_on_after_a_save(): void
    {
        $this->openDraft(null);

        $this->actingAs($this->ao)->get($this->url('show'))->assertOk()->assertSee('name="sanctioned_total"', false);

        $this->saveSanctioned()->assertRedirect($this->url('show', ['section' => 'working']));
        $this->actingAs($this->ao)->get($this->url('show'))->assertOk()->assertSee('name="working_total"', false);

        $this->saveWorking()->assertRedirect($this->url('show', ['section' => 'vacancies']));
        $this->actingAs($this->ao)->get($this->url('show'))->assertOk()->assertSee('id="add-b1"', false)->assertSee('id="add-c"', false);

        // A refused save stays on its own step.
        $this->saveWorking(['working_total' => '70', 'correction_reason' => ''])->assertRedirect($this->url('show', ['section' => 'working']));
    }

    public function test_a_refused_save_shows_its_message_beside_the_field_it_is_about(): void
    {
        $this->openDraft();
        $here = $this->url('show', ['section' => 'working']);

        // A business rule (the sanctioned ceiling), refused by the service.
        $this->from($here)->saveWorking(['working_total' => '101'])
            ->assertRedirect($here)
            ->assertSessionHasErrors('working_total');
        $html = $this->revisit($here)->assertOk()->getContent();
        $message = e(__('vacancymaster.errors.working_over_sanctioned', ['working' => 101, 'sanctioned' => 100]));

        $this->assertSame(1, substr_count($html, $message), 'The message appears once, beside the field, not again at the top.');
        $this->assertStringContainsString(e(__('vacancymaster.errors.fix_highlighted')), $html);
        $this->assertMatchesRegularExpression('/name="working_total"[^>]*autofocus/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*is-invalid[^"]*"[^>]*name="working_total"/', $html);

        // A field rule (the form request), named the way the form labels it.
        $this->from($here)->saveWorking(['order_date' => 'not a date'])->assertRedirect($here)->assertSessionHasErrors('order_date');
        $html = $this->revisit($here)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="order_date"[^>]*autofocus/', $html);
        $this->assertStringContainsString(__('vacancymaster.order_date'), $html);
    }

    public function test_a_rows_message_stays_with_that_row_and_its_form(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->addEntry()->assertSessionHasNoErrors();
        $entry = VacancyPositionEntry::query()->latest('id')->firstOrFail();
        $here = $this->url('show', ['section' => 'vacancies']);

        $this->actingAs($this->ao)->from($here)
            ->post($this->url('entries.withdraw', [$entry]), ['_entry' => (string) $entry->id, 'withdrawal_reason' => ''])
            ->assertRedirect($here)
            ->assertSessionHasErrors('withdrawal_reason', null, 'entry_'.$entry->id);

        $html = $this->revisit($here)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/is-invalid[^"]*"[^>]*name="withdrawal_reason"|name="withdrawal_reason"[^>]*is-invalid/', $html);
        // The refused row's reason opens again, folded rows stay folded.
        $this->assertStringContainsString('class="collapse vm-withdraw show" id="withdraw-'.$entry->id.'"', $html);

        // The add forms are untouched.
        $this->assertStringNotContainsString('is-invalid', substr($html, (int) strpos($html, 'id="add-b1"'), (int) strpos($html, 'id="vacancy-b2"') - (int) strpos($html, 'id="add-b1"')));

        // A refused B2 row marks the B2 form only.
        $this->actingAs($this->ao)->from($here)->post($this->url('entries.store'), ['kind' => 'deputation', 'officer_name' => 'K. Verma'])
            ->assertSessionHasErrors(['deputation_from', 'promotion_made']);
        $html = $this->revisit($here)->assertOk()->getContent();
        $b2 = substr($html, (int) strpos($html, 'id="add-b2"'), (int) strpos($html, 'id="vacancy-c"') - (int) strpos($html, 'id="add-b2"'));
        $this->assertStringContainsString('is-invalid', $b2);
        // Its panel opens by itself; the others stay folded.
        $this->assertStringContainsString('class="collapse border-top show" id="add-b2-panel"', $html);
        $this->assertStringContainsString('class="collapse border-top" id="add-b1-panel"', $html);
        $this->assertStringNotContainsString('is-invalid', substr($html, (int) strpos($html, 'id="add-c"')));
    }

    // -------------------------------------------- step 2: working strength

    public function test_missing_working_strength_is_not_treated_as_zero(): void
    {
        $this->openDraft();
        $b = $this->breakdown();

        $this->assertNull($b['working']);
        $this->assertNull($b['clear_vacancy']);
        $this->assertFalse($b['complete']);
        $this->assertContains('working_not_entered', array_column($b['issues'], 'key'));
    }

    public function test_working_strength_is_one_figure_with_an_optional_order(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $working = $position->fresh()->workingStrength;
        $this->assertSame(75, $working->working_total);
        $this->assertSame('SE/EST/2026/01', $working->order_number);
        Storage::disk($working->disk)->assertExists($working->path);
        $this->actingAs($this->ao)->get($this->url('working-strength.document', [$working]))->assertOk();

        $this->assertSame(25, $this->breakdown()['clear_vacancy']);

        // Without the order and PDF it is still saved.
        $this->saveWorking(['working_total' => '74', 'order_number' => '', 'order_date' => '', 'document' => null, 'correction_reason' => 'One relieved.'])
            ->assertSessionHasNoErrors();
        $this->assertNull($position->fresh()->workingStrength->order_number);
        $this->assertNull($position->fresh()->workingStrength->path);
        $this->assertSame(26, $this->breakdown()['clear_vacancy']);
    }

    public function test_a_correction_needs_a_reason_and_keeps_the_history(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->saveWorking(['working_total' => '74'])->assertSessionHasErrors('correction_reason');
        $this->saveWorking(['working_total' => '74', 'correction_reason' => 'One officer was relieved on 31-12-2025.'])->assertSessionHasNoErrors();

        $history = $position->fresh()->workingStrengthHistory;
        $this->assertSame([75, 74], $history->pluck('working_total')->all());
        $this->assertSame('One officer was relieved on 31-12-2025.', $history->last()->correction_reason);
        $this->assertTrue(ActivityTimeline::query()->where('event', 'vacancy_position.working_strength_corrected')->exists());
    }

    /**
     * Working strength = our officers + officers here on deputation, never above the
     * sanctioned strength.
     */
    public function test_working_strength_counts_officers_here_on_deputation_and_never_exceeds_sanctioned(): void
    {
        $this->openDraft(100);
        $this->saveWorking(['working_total' => '101'])->assertSessionHasErrors('working_total');
        $this->saveWorking(['working_total' => '99'])->assertSessionHasNoErrors();

        $this->addEntry(['kind' => 'deputation_in', 'officer_name' => 'A. Khan', 'deputation_from' => '2024-04-01', 'deputation_to' => '2027-03-31'])->assertSessionHasNoErrors();
        $this->assertSame(100, $this->breakdown()['working']['total']);
        $this->assertSame(1, $this->breakdown()['working']['deputation_in']);

        $this->addEntry(['kind' => 'deputation_in', 'officer_name' => 'B. Rao', 'deputation_from' => '2024-04-01', 'deputation_to' => '2027-03-31'])
            ->assertSessionHasErrors('kind');
        $this->assertSame(1, VacancyPositionEntry::query()->count(), 'The refused row is not kept.');
    }

    // -------------------------------------------- step 3: A + B1 + B2 + C

    /**
     * The worked example of 2026-10-30: sanctioned 10, our officers 6, two here on
     * deputation → working 8, clear 2; one deputation here ends in the year, one of
     * ours has been away more than a year with no promotion made, two retire and one
     * is promoted to a higher post → total 7; 7 × 50 % = 3.5 → 4 promotion, 3 direct.
     */
    public function test_the_total_is_clear_plus_deputation_plus_anticipated_and_the_pre_dpc_share_of_it(): void
    {
        $higher = $this->makePost($this->department, 'Joint Director', 5);
        $this->openDraft(10);
        $this->saveWorking(['working_total' => '6'])->assertSessionHasNoErrors();

        // B1: in the working strength; the post falls vacant when the deputation ends in the year.
        $this->addEntry(['kind' => 'deputation_in', 'officer_name' => 'A. Khan', 'deputation_from' => '2024-07-01', 'deputation_to' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->addEntry(['kind' => 'deputation_in', 'officer_name' => 'B. Rao', 'deputation_from' => '2025-07-01', 'deputation_to' => '2028-06-30'])->assertSessionHasNoErrors();

        // B2 (rule 2.3): more than a year by 31 December and no promotion made.
        $this->addEntry(['kind' => 'deputation', 'officer_name' => 'C. Patel', 'deputation_from' => '2025-03-01', 'promotion_made' => '0'])->assertSessionHasNoErrors();
        $this->addEntry(['kind' => 'deputation', 'officer_name' => 'D. Singh', 'deputation_from' => '2026-02-01', 'promotion_made' => '0'])->assertSessionHasNoErrors();
        $this->addEntry(['kind' => 'deputation', 'officer_name' => 'E. Das', 'deputation_from' => '2024-01-01', 'promotion_made' => '1'])->assertSessionHasNoErrors();

        // C: two retirements on their own dates, one promotion to a higher post.
        $this->addEntry(['officer_name' => 'F. Gupta', 'effective_date' => '2026-04-30'])->assertSessionHasNoErrors();
        $this->addEntry(['officer_name' => 'G. Mishra', 'effective_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->addEntry([
            'reason' => 'higher_post_promotion', 'officer_name' => 'H. Joshi', 'effective_date' => '2026-08-01',
            'higher_post_id' => (string) $higher->id, 'higher_dpc_reference' => 'DPC-2026-SE-0007', 'higher_post_undertaking' => '1',
            'order_number' => 'SE/PROM/2026/9', 'order_date' => '2026-01-20', 'document' => $this->pdf('promotion.pdf'),
        ])->assertSessionHasNoErrors();

        $b = $this->breakdown();
        $this->assertSame(8, $b['working']['total']);
        $this->assertSame(2, $b['clear_vacancy']);
        $this->assertSame(1, $b['deputation_in_vacancy']);
        $this->assertSame(1, $b['deputation_out_vacancy']);
        $this->assertSame(3, $b['anticipated_vacancy']);
        $this->assertSame(2, $b['retirement_vacancy']);
        $this->assertSame(7, $b['total_vacancy']);
        $this->assertSame('50.00', $b['promotion_percentage']);
        $this->assertSame(4, $b['final_quota']['promotion_vacancy']);
        $this->assertSame(3, $b['final_quota']['direct_vacancy']);
        $this->assertSame([], $b['issues']);

        // Each row says whether it counts, and why.
        $this->actingAs($this->ao)->get($this->url('show', ['section' => 'vacancies']))
            ->assertOk()
            ->assertSee(__('vacancymaster.notes.deputation_in_continues', ['date' => '30-06-2028', 'year' => 2026]))
            ->assertSee(__('vacancymaster.notes.deputation_out_within_a_year', ['date' => '31-12-2026']))
            ->assertSee(__('vacancymaster.notes.deputation_out_promotion_made'));

        $this->actingAs($this->ao)->get($this->url('show', ['section' => 'summary']))
            ->assertOk()
            ->assertSee('data-figure="total">7<', false)
            ->assertSee('data-figure="promotion">', false);

        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();
        $position = VacancyPosition::query()->sole();
        $this->assertSame(4, $position->promotion_vacancy);
        $this->assertSame(3, $position->direct_vacancy);
        $this->assertSame(2, $position->opening_vacancy);
    }

    public function test_a_deputation_here_must_end_after_it_began_and_not_before_the_year(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $row = ['kind' => 'deputation_in', 'officer_name' => 'A. Khan', 'deputation_from' => '2024-07-01'];

        $this->addEntry([...$row, 'deputation_to' => null])->assertSessionHasErrors('deputation_to');
        $this->addEntry([...$row, 'deputation_to' => '2024-06-30'])->assertSessionHasErrors('deputation_to');
        // Already over before 1 January: not working here, so refused.
        $this->addEntry([...$row, 'deputation_to' => '2025-12-31'])->assertSessionHasErrors('deputation_to');
        $this->addEntry([...$row, 'deputation_to' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->addEntry([...$row, 'officer_name' => 'B. Rao', 'deputation_to' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->addEntry([...$row, 'officer_name' => 'C. Patel', 'deputation_to' => '2027-01-01'])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->breakdown()['deputation_in_vacancy'], 'Ending on 1 January and on 31 December both count; 1 January next year does not.');
    }

    public function test_our_officer_on_deputation_counts_after_more_than_a_year_with_no_promotion_made(): void
    {
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $row = ['kind' => 'deputation', 'promotion_made' => '0'];

        $this->addEntry([...$row, 'officer_name' => 'X', 'promotion_made' => null, 'deputation_from' => '2025-01-01'])->assertSessionHasErrors('promotion_made');
        $this->addEntry([...$row, 'officer_name' => 'X', 'deputation_from' => '2027-01-01'])->assertSessionHasErrors('deputation_from');

        // Exactly one year by 31 December is not more than a year.
        $this->addEntry([...$row, 'officer_name' => 'One year', 'deputation_from' => '2025-12-31'])->assertSessionHasNoErrors();
        $this->addEntry([...$row, 'officer_name' => 'A day more', 'deputation_from' => '2025-12-30'])->assertSessionHasNoErrors();
        $this->addEntry([...$row, 'officer_name' => 'Promoted against', 'deputation_from' => '2023-01-01', 'promotion_made' => '1'])->assertSessionHasNoErrors();

        $counted = VacancyPositionEntry::query()->orderBy('id')->get()->mapWithKeys(fn ($e) => [$e->officer_name => $e->isCounted()])->all();
        $this->assertSame(['One year' => false, 'A day more' => true, 'Promoted against' => false], $counted);
        $this->assertTrue(VacancyPositionEntry::query()->where('officer_name', 'Promoted against')->sole()->promotion_made);
        $this->assertSame(1, $this->breakdown()['deputation_out_vacancy']);
    }

    public function test_anticipated_rows_are_retirements_or_promotions_inside_the_year_one_officer_each(): void
    {
        [$from, $to] = app(VacancyEngine::class)->periodForYear(self::YEAR);
        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-12-31', $to->toDateString());

        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $this->addEntry(['officer_name' => 'A', 'effective_date' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->addEntry(['officer_name' => 'B', 'effective_date' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->addEntry(['officer_name' => 'C', 'effective_date' => '2027-01-01'])->assertSessionHasErrors('effective_date');
        $this->addEntry(['officer_name' => 'D', 'effective_date' => '2025-12-31'])->assertSessionHasErrors('effective_date');
        $this->addEntry(['officer_name' => 'E', 'reason' => 'death'])->assertSessionHasErrors('reason');
        $this->addEntry(['officer_name' => 'F', 'kind' => 'existing'])->assertSessionHasErrors('kind');
        $this->addEntry(['officer_name' => ''])->assertSessionHasErrors('officer_name');
        // The same officer twice.
        $this->addEntry(['officer_name' => ' a ', 'effective_date' => '2026-05-01'])->assertSessionHasErrors('officer_name');

        $this->assertSame(2, VacancyPositionEntry::query()->count());
        $this->assertSame(2, $this->breakdown()['anticipated_vacancy']);
        $this->assertNull(VacancyPositionEntry::query()->first()->order_number, 'A retirement needs no order.');
    }

    public function test_a_promotion_to_a_higher_post_needs_its_undertaking_dpc_and_order(): void
    {
        $higher = $this->makePost($this->department, 'Joint Director', 10);
        $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();

        $row = ['reason' => 'higher_post_promotion', 'officer_name' => 'H. Joshi', 'effective_date' => '2026-08-01',
            'higher_post_id' => (string) $higher->id, 'higher_dpc_reference' => 'DPC-2026-SE-0007', 'higher_post_undertaking' => '1',
            'order_number' => 'SE/PROM/2026/9', 'order_date' => '2026-01-20'];

        // Rule 1.2: not without the undertaking, the DPC and the order with its PDF.
        $this->addEntry([...$row, 'higher_post_undertaking' => null, 'document' => $this->pdf()])->assertSessionHasErrors('higher_post_undertaking');
        $this->addEntry([...$row, 'higher_dpc_reference' => '', 'document' => $this->pdf()])->assertSessionHasErrors('higher_dpc_reference');
        $this->addEntry([...$row, 'higher_post_id' => (string) $this->principal->id, 'document' => $this->pdf()])->assertSessionHasErrors('higher_post_id');
        $this->addEntry([...$row, 'order_number' => '', 'document' => $this->pdf()])->assertSessionHasErrors('order_number');
        $this->addEntry($row)->assertSessionHasErrors('document');
        $this->addEntry([...$row, 'document' => $this->pdf()])->assertSessionHasNoErrors();

        $entry = VacancyPositionEntry::query()->sole();
        $this->assertNotNull($entry->higher_post_undertaking_at);
        $this->assertSame('SE/PROM/2026/9', $entry->promotion_order_number);
        $this->assertTrue($entry->isCounted(), 'Counted when saved — nobody verifies it afterwards.');
        $this->assertSame(1, $this->breakdown()['anticipated_vacancy']);
    }

    /**
     * The total vacancy never exceeds the sanctioned strength.
     */
    public function test_the_total_can_never_exceed_the_sanctioned_strength(): void
    {
        $this->openDraft(10);
        $this->saveWorking(['working_total' => '6'])->assertSessionHasNoErrors();   // clear 4

        foreach (range(1, 6) as $n) {
            $this->addEntry(['officer_name' => 'Officer '.$n, 'effective_date' => '2026-0'.$n.'-15'])->assertSessionHasNoErrors();
        }

        $this->assertSame(10, $this->breakdown()['total_vacancy']);
        $this->addEntry(['officer_name' => 'Officer 7', 'effective_date' => '2026-07-15'])->assertSessionHasErrors('kind');
        $this->assertSame(6, VacancyPositionEntry::query()->count());
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

    // ---------------------------------------------------- finalize & history

    public function test_finalization_requires_a_complete_position(): void
    {
        $position = $this->openDraft();

        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasErrors('workflow');
        $this->assertSame(VacancyPositionStatus::Draft, $position->fresh()->status);

        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $position->refresh();
        $this->assertSame(VacancyPositionStatus::Finalized, $position->status);
        $this->assertSame(100, $position->sanctioned_strength);
        $this->assertSame(25, $position->opening_vacancy);
        // 25 × 50 % = 12.5 → 13 (rule 1.3), 12 direct.
        $this->assertSame(13, $position->promotion_vacancy);
        $this->assertSame(12, $position->direct_vacancy);
        $this->assertSame('50.00', $position->promotion_percentage);
        $this->assertSame('round', $position->rounding_rule);

        // Frozen: no further edit reaches it.
        $this->saveWorking(['working_total' => '70', 'correction_reason' => 'late'])->assertSessionHasErrors('workflow');
        $this->addEntry()->assertSessionHasErrors('workflow');
        $this->assertSame(75, $position->fresh()->workingStrength->working_total);
    }

    public function test_a_finalized_position_keeps_its_snapshot_when_the_pre_dpc_or_master_changes(): void
    {
        $position = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->principal->forceFill(['sanctioned_strength' => 120])->save();
        $this->preDpc->forceFill(['promotion_recruitment_pct' => '70.00'])->save();

        $b = app(VacancyMasterService::class)->breakdown($position->fresh());
        $this->assertSame(100, $b['sanctioned']);
        $this->assertSame('50.00', $b['promotion_percentage']);
        $this->assertSame(13, $b['final_quota']['promotion_vacancy']);
    }

    public function test_a_correction_after_finalization_is_a_new_revision(): void
    {
        $first = $this->openDraft();
        $this->saveWorking()->assertSessionHasNoErrors();
        $this->addEntry(['officer_name' => 'A'])->assertSessionHasNoErrors();
        $this->addEntry(['officer_name' => 'B'])->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->actingAs($this->ao)->post($this->url('revise'), [])->assertSessionHasErrors('revision_reason');
        $this->actingAs($this->ao)->post($this->url('revise'), ['revision_reason' => 'Retirement order corrected.'])->assertSessionHasNoErrors();

        $second = app(VacancyMasterService::class)->current($this->department->id, 'Principal', self::YEAR);
        $this->assertSame(2, $second->revision);
        $this->assertTrue($second->isDraft());
        $this->assertSame(75, $second->workingStrength->working_total, 'The working strength is carried forward.');
        $this->assertSame(2, $second->entries()->count(), 'Active rows are carried forward.');

        // Until the revision is finalized the first stays the authoritative one.
        $this->assertSame($first->id, app(VacancyMasterService::class)->finalized($this->department->id, 'Principal', self::YEAR)->id);

        $entry = $second->entries()->where('officer_name', 'B')->sole();
        $this->actingAs($this->ao)->post($this->url('entries.withdraw', [$entry]), ['withdrawal_reason' => 'Order cancelled.'])->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post($this->url('finalize'))->assertSessionHasNoErrors();

        $this->assertSame(VacancyPositionStatus::Superseded, $first->fresh()->status);
        // 27 × 50 % = 13.5 → 14; then 26 → 13.
        $this->assertSame(14, $first->fresh()->promotion_vacancy, 'The first revision keeps its own figures.');
        $this->assertSame(13, $second->fresh()->promotion_vacancy);
        $this->assertSame($second->id, app(VacancyMasterService::class)->finalized($this->department->id, 'Principal', self::YEAR)->id);
        $this->assertSame(VacancyPositionEntry::STATUS_WITHDRAWN, $entry->fresh()->status);

        $this->actingAs($this->ao)->get($this->url('show'))
            ->assertOk()
            ->assertSee('Retirement order corrected.');
    }

    // ------------------------------------------------- seniority & proposals

    public function test_the_seniority_reference_date_is_first_april_of_the_previous_year(): void
    {
        $this->assertSame('2025-04-01', app(VacancyMasterService::class)->seniorityReferenceDate(2026)->toDateString());

        $this->actingAs($this->ao)->get($this->url('show'))
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
        $position = $service->openDraft($this->department, $to->fresh(), self::YEAR, $this->ao);

        // A draft is never authoritative.
        $this->assertNotContains('vacancy_master', $keys());

        $service->saveSanctionedStrength($position, [
            'sanctioned_total' => $sums['sanctioned'], 'order_number' => 'S', 'order_date' => '2026-01-02',
            // The proposal's fixture recorded the department's figure first.
            'correction_reason' => 'Confirmed.',
        ], $this->pdf(), $this->ao);

        $service->saveWorkingStrength($position->fresh(), ['working_total' => $sums['working'] + 1], null, $this->ao);
        $service->finalize($position->fresh(), $this->ao);

        $section = collect($completeness->checklist($proposal->fresh()))->firstWhere('key', 'vacancy_master');
        $this->assertFalse($section['complete']);
        $this->assertStringContainsString('Working officers differ', $section['detail']);

        // Corrected by revision to agree with the chart: the proposal may now go.
        $draft = $service->revise($position->fresh(), 'Count corrected.', $this->ao);
        $service->saveWorkingStrength($draft, ['working_total' => $sums['working'], 'correction_reason' => 'One officer fewer.'], null, $this->ao);
        $service->finalize($draft->fresh(), $this->ao);

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

    // ------------------------------------------------ old drafts on UAT

    public function test_old_drafts_are_listed_by_default_and_deleted_only_with_apply(): void
    {
        $kept = $this->openDraft();
        // A draft kept before 2026-10-30: no Pre-DPC.
        $old = VacancyPosition::query()->create([
            'department_id' => $this->department->id, 'post_name' => 'Vice Principal', 'vacancy_year' => self::YEAR,
            'revision' => 1, 'status' => VacancyPositionStatus::Draft->value, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        ]);
        $finalized = VacancyPosition::query()->create([
            'department_id' => $this->department->id, 'post_name' => 'Vice Principal', 'vacancy_year' => 2025,
            'revision' => 1, 'status' => VacancyPositionStatus::Finalized->value, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
        ]);

        // The old draft carries its figures and a row, which go with it.
        $service = app(VacancyMasterService::class);
        $service->saveSanctionedStrength($old, ['sanctioned_total' => 10, 'order_number' => 'SANC/1', 'order_date' => '2026-01-02'], $this->pdf(), $this->ao);
        $service->saveWorkingStrength($old->fresh(), ['working_total' => 8], null, $this->ao);
        $service->addEntry($old->fresh(), ['kind' => 'anticipated', 'reason' => 'retirement', 'officer_name' => 'R. Sharma', 'effective_date' => '2026-06-01'], null, $this->ao);
        $this->assertSame(1, VacancyPositionEntry::query()->where('vacancy_position_id', $old->id)->count());

        $this->artisan('dpc:purge-old-vacancy-drafts')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(3, VacancyPosition::query()->count());
        $this->assertSame(1, VacancyPositionEntry::query()->where('vacancy_position_id', $old->id)->count());

        $this->artisan('dpc:purge-old-vacancy-drafts', ['--apply' => true])->expectsOutputToContain('1 old draft(s) deleted')->assertSuccessful();

        $this->assertNull(VacancyPosition::query()->find($old->id));
        $this->assertSame(0, VacancyPositionEntry::query()->where('vacancy_position_id', $old->id)->count());
        $this->assertSame(0, DB::table('vacancy_position_sanctioned_strengths')->where('vacancy_position_id', $old->id)->count());
        $this->assertSame(0, DB::table('vacancy_position_working_strengths')->where('vacancy_position_id', $old->id)->count());
        $this->assertNotNull(VacancyPosition::query()->find($kept->id)?->sanctioned_strength_id, "The kept draft's figures are untouched.");
        $this->assertNotNull(VacancyPosition::query()->find($kept->id), 'A draft of a Pre-DPC is kept.');
        $this->assertNotNull(VacancyPosition::query()->find($finalized->id), 'A finalized record is history and is kept.');
    }
}
