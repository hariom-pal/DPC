<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationPropertyFlag;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The CR window, Missing / NG, "Appointed in this year" and their correction by Edit
 * or Delete, and the Property File's equivalents — driven over HTTP as the AO does it,
 * with the database state, the screen's own year list and the audit trail checked
 * after every step (review of 2026-11-19, TC01–TC70, TC121–TC150).
 *
 * Offsets count back from the year before the DPC year: 0 is that year (the latest,
 * never replaced), 4 the oldest of the five.
 */
class CrWindowMatrixTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    private int $last;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk'));

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);

        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Under Secretary'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());

        $this->row = $this->proposal->evaluationRows()->sole();
        $this->last = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year) - 1;
    }

    // ------------------------------------------------------------------ steps

    private function route(string $name, array $extra = []): string
    {
        return route("dpc-proposals.employees.{$name}", [$this->proposal, $this->row, ...$extra]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('document.pdf', 20, 'application/pdf');
    }

    /** The Seniority List's Date of Joining in Service, in the year recorded "appointed in this year" (2026-11-22). */
    private function joinedIn(int $year): void
    {
        $this->row->forceFill(['date_of_joining_service' => $year.'-07-01'])->save();
    }

    private function reason(string $code): int
    {
        return (int) RecordReason::query()->where('code', $code)->value('id');
    }

    /** One step: G graded, M missing, N put up without grading (NG), A appointed in this year. */
    private function step(string $kind, int $offset, array $extra = []): TestResponse
    {
        $year = $this->last - $offset;
        $payload = match ($kind) {
            'G' => ['year' => $year, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क',
                'integrity_option_id' => (int) IntegrityOption::query()->where('code', 'certified_good')->value('id')],
            'N' => ['year' => $year, 'put_up' => '1', 'has_grading' => '0'],
            'M' => ['year' => $year, 'put_up' => '0', 'missing_reason_id' => $this->reason('long_leave'), 'missing_document' => $this->pdf()],
            'A' => ['year' => $year, 'put_up' => '0', 'missing_reason_id' => $this->reason('newly_appointed'), 'missing_document' => $this->pdf()],
        };
        if ($kind === 'A') {
            $this->joinedIn($year);
        }

        return $this->actingAs($this->ao)->post($this->route('cr.save'), [...$payload, ...$extra]);
    }

    /** @param list<array{0: string, 1: int}> $steps */
    private function apply(array $steps): void
    {
        foreach ($steps as [$kind, $offset]) {
            $this->step($kind, $offset)->assertSessionHasNoErrors();
        }
    }

    /** @return list<int> offsets */
    private function offsets(array $years): array
    {
        return array_map(fn (int $year): int => $this->last - $year, $years);
    }

    /** The CR form's own year list, as offsets (the page loads: TC30). */
    private function crOptions(): array
    {
        return $this->offsets($this->yearList('cr-year'));
    }

    /** @return list<int> years */
    private function yearList(string $selectId): array
    {
        $html = (string) $this->actingAs($this->ao)->get($this->route('show'))->assertOk()->getContent();

        if (! preg_match('/<select[^>]*id="'.$selectId.'"[^>]*>(.*?)<\/select>/s', $html, $select)) {
            return [];
        }

        preg_match_all('/<option value="(\d{4})"/', $select[1], $years);

        return array_map('intval', $years[1]);
    }

    private function window(): array
    {
        return $this->offsets(app(EmployeeDetailsService::class)->crYearWindow($this->row->fresh()));
    }

    /** No year carries two CR, Property File or gap rows (TC103, TC129). */
    private function assertNoDuplicates(): void
    {
        foreach ([DpcEvaluationCrGrade::class, DpcEvaluationPropertyFlag::class] as $model) {
            $years = $model::query()->where('dpc_evaluation_row_id', $this->row->id)->pluck('year')->all();
            $this->assertSame(count($years), count(array_unique($years)), $model);
        }
        $keys = DpcEvaluationRecordGap::query()->where('dpc_evaluation_row_id', $this->row->id)->get()->map(fn ($g) => $g->kind.$g->year)->all();
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    // ------------------------------------------------- TC01–TC19: the window

    /**
     * @return array<string, array{list<array{0: string, 1: int}>, list<int>, list<int>}>
     */
    public static function windows(): array
    {
        $g = fn (int ...$o): array => array_map(fn (int $x): array => ['G', $x], $o);

        return [
            'TC01 five graded' => [$g(0, 1, 2, 3, 4), [0, 1, 2, 3, 4], []],
            'TC02 the latest missing (held, never replaced)' => [[['M', 0], ...$g(1, 2, 3, 4)], [0, 1, 2, 3, 4], []],
            'TC03 a middle year missing' => [[...$g(0, 1), ['M', 2], ...$g(3, 4)], [0, 1, 2, 3, 4, 5], [5]],
            'TC04 the oldest year missing' => [[...$g(0, 1, 2, 3), ['M', 4]], [0, 1, 2, 3, 4, 5], [5]],
            'TC05 the latest NG (held, never replaced)' => [[['N', 0], ...$g(1, 2, 3, 4)], [0, 1, 2, 3, 4], []],
            'TC06 a middle year NG' => [[...$g(0, 1), ['N', 2], ...$g(3, 4)], [0, 1, 2, 3, 4, 5], [5]],
            'TC07 the oldest year NG' => [[...$g(0, 1, 2, 3), ['N', 4]], [0, 1, 2, 3, 4, 5], [5]],
            'TC08 two missing apart' => [[['G', 0], ['M', 1], ['G', 2], ['M', 3], ['G', 4]], [0, 1, 2, 3, 4, 5, 6], [5, 6]],
            'TC09 two missing together' => [[['G', 0], ['M', 1], ['M', 2], ...$g(3, 4)], [0, 1, 2, 3, 4, 5, 6], [5, 6]],
            'TC10 two NG together' => [[['G', 0], ['N', 1], ['N', 2], ...$g(3, 4)], [0, 1, 2, 3, 4, 5, 6], [5, 6]],
            'TC11 missing and NG' => [[['G', 0], ['M', 1], ['N', 2], ...$g(3, 4)], [0, 1, 2, 3, 4, 5, 6], [5, 6]],
            'TC12 missing, graded, NG, graded' => [[['G', 0], ['M', 1], ['G', 2], ['N', 3], ['G', 4]], [0, 1, 2, 3, 4, 5, 6], [5, 6]],
            'TC13/TC19 four graded, one missing, completed' => [[...$g(0, 1), ['M', 2], ...$g(3, 4, 5)], [0, 1, 2, 3, 4, 5], []],
            'TC14 four graded, one NG, completed' => [[...$g(0, 1), ['N', 2], ...$g(3, 4, 5)], [0, 1, 2, 3, 4, 5], []],
            'TC15 three graded, two missing, completed' => [[['G', 0], ['M', 1], ['M', 2], ...$g(3, 4, 5, 6)], [0, 1, 2, 3, 4, 5, 6], []],
            'TC16 all five first asked missing' => [[['M', 0], ['M', 1], ['M', 2], ['M', 3], ['M', 4]], [0, 1, 2, 3, 4, 5, 6, 7, 8], [5, 6, 7, 8]],
        ];
    }

    /**
     * @param  list<array{0: string, 1: int}>  $steps
     * @param  list<int>  $window
     * @param  list<int>  $offered
     */
    #[DataProvider('windows')]
    public function test_the_window_and_the_year_list_follow_missing_and_ng(array $steps, array $window, array $offered): void
    {
        $this->apply($steps);

        $this->assertSame($window, $this->window());
        $this->assertSame($offered, $this->crOptions(), 'The screen offers exactly the years still to record.');
        $this->assertNoDuplicates();

        // TC20 / TC91: a year beyond the window is refused on the server too.
        $this->step('G', max($window) + 1)->assertSessionHasErrors('cr');
        $this->assertFalse(DpcEvaluationCrGrade::query()->where('year', $this->last - max($window) - 1)->exists());
    }

    /** TC18: fifteen in a row missing — the window keeps reaching back. */
    public function test_fifteen_missing_in_a_row_keep_reaching_back(): void
    {
        $this->step('G', 0)->assertSessionHasNoErrors();
        foreach (range(1, 15) as $offset) {
            $this->assertContains($offset, $this->crOptions());
            $this->step('M', $offset)->assertSessionHasNoErrors();
        }

        $this->assertSame(range(0, 19), $this->window());
        $this->assertSame([16, 17, 18, 19], $this->crOptions());
    }

    /** TC99: a stored rule of no CR years is taken as one — no window runs backwards, no page fails. */
    public function test_a_rule_of_zero_cr_years_is_taken_as_one(): void
    {
        app(PromotionCrRules::class)->ruleForProposal($this->proposal)->forceFill(['cr_years' => 0])->save();
        $this->app->forgetInstance(PromotionCrRules::class);

        $this->assertSame([0], $this->window());
        $this->assertSame([0], $this->crOptions());
        $this->actingAs($this->ao)->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertForbidden();
    }

    // ------------------------------------------- TC21–TC30: edit and delete

    public function test_edits_and_deletes_keep_the_window_and_the_records_right(): void
    {
        $this->apply([['G', 0], ['G', 1], ['G', 2], ['G', 3], ['G', 4]]);
        $this->assertSame([], $this->crOptions());

        // TC21: a graded year deleted after completion is offered again.
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 2]))->assertSessionHasNoErrors();
        $this->assertSame([2], $this->crOptions());

        // TC29: recreated.
        $this->step('G', 2)->assertSessionHasNoErrors();
        $this->assertSame([], $this->crOptions());

        // TC22 / TC23: graded edited to missing, then to NG — one older year asked.
        $this->step('M', 2)->assertSessionHasNoErrors();
        $this->assertSame([5], $this->crOptions());
        $this->step('N', 2)->assertSessionHasNoErrors();
        $this->assertSame([5], $this->crOptions());
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('year', $this->last - 2)->count(), 'NG needs no missing reason: the old one is withdrawn.');

        // TC25: NG back to graded — back to five.
        $this->step('G', 2)->assertSessionHasNoErrors();
        $this->assertSame([], $this->crOptions());

        // TC24: missing back to graded — its reason withdrawn.
        $this->step('M', 3)->assertSessionHasNoErrors();
        $this->assertSame(1, DpcEvaluationRecordGap::query()->where('year', $this->last - 3)->count());
        $this->step('G', 3)->assertSessionHasNoErrors();
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('year', $this->last - 3)->count());

        // TC26: the missing reason changed, still missing: one gap, the new reason.
        $this->step('M', 3)->assertSessionHasNoErrors();
        $this->step('M', 3, ['missing_reason_id' => $this->reason('record_lost')])->assertSessionHasNoErrors();
        $this->assertSame('record_lost', DpcEvaluationRecordGap::query()->where('year', $this->last - 3)->sole()->reason->code);

        // TC27 / TC28: the grade changed, again and again, on the same year: one row.
        foreach (['ख', 'क+', 'क'] as $grade) {
            $this->step('G', 1, ['grade' => $grade])->assertSessionHasNoErrors();
        }
        $this->assertSame('क', DpcEvaluationCrGrade::query()->where('year', $this->last - 1)->sole()->grade);

        $this->assertNoDuplicates();
    }

    /** TC130: removing a year twice, or one never recorded, is harmless. */
    public function test_repeated_deletes_are_harmless(): void
    {
        $this->apply([['G', 0], ['M', 2]]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 2]))->assertRedirect();
        }
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 9]))->assertRedirect();

        $this->assertSame(0, DpcEvaluationRecordGap::query()->count());
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'dpc_proposal.record_gap_withdrawn')->count(), 'Withdrawn once.');
    }

    // ------------------------------------------ TC31–TC50: appointed in this year

    /**
     * @return array<string, array{list<array{0: string, 1: int}>, list<int>, list<int>}>
     */
    public static function appointments(): array
    {
        return [
            'TC31/TC34 appointed in the latest year' => [[['A', 0]], [0], []],
            'TC32 appointed in the middle' => [[['G', 0], ['G', 1], ['A', 2]], [0, 1, 2], []],
            'TC33 appointed in the oldest of five' => [[['G', 0], ['G', 1], ['G', 2], ['G', 3], ['A', 4]], [0, 1, 2, 3, 4], []],
            'TC35 every year after it missing' => [[['G', 0], ['M', 1], ['M', 2], ['A', 3]], [0, 1, 2, 3], []],
            'TC36 missing and NG after it' => [[['G', 0], ['M', 1], ['N', 2], ['A', 3]], [0, 1, 2, 3], []],
        ];
    }

    /**
     * @param  list<array{0: string, 1: int}>  $steps
     * @param  list<int>  $window
     * @param  list<int>  $offered
     */
    #[DataProvider('appointments')]
    public function test_nothing_before_the_year_of_appointment_is_asked_or_accepted(array $steps, array $window, array $offered): void
    {
        $this->apply($steps);

        $this->assertSame($window, $this->window());
        $this->assertSame($offered, $this->crOptions());

        // TC42 / TC44 / TC127: a direct or stale request for an older year is refused.
        $this->step('G', max($window) + 1)->assertSessionHasErrors('cr');
        $this->step('M', max($window) + 1)->assertSessionHasErrors('cr');
        $this->assertFalse(DpcEvaluationCrGrade::query()->where('year', '<', $this->last - max($window))->exists());
    }

    /** TC40, TC41, TC45, TC47, TC49, TC50, TC144, TC147. */
    public function test_the_appointment_moved_corrected_and_its_history_kept(): void
    {
        $this->apply([['G', 0], ['A', 2]]);
        $this->assertSame([0, 1, 2], $this->window());

        // TC41: an older, contradicting appointment is refused (before the boundary).
        $this->step('A', 4)->assertSessionHasErrors('cr');

        // TC47: edited back and forth.
        $this->step('M', 2)->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear());
        $this->step('A', 2)->assertSessionHasNoErrors();
        $this->assertSame($this->last - 2, $this->row->fresh()->appointedYear());

        // TC40: deleted, then given to another year.
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 2]))->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear(), 'TC49: no active appointment left.');
        $this->step('A', 1)->assertSessionHasNoErrors();
        $this->assertSame([0, 1], $this->window());
        // TC45: the year freed a moment ago is now before the new boundary: refused.
        $this->step('G', 2)->assertSessionHasErrors('cr');

        // TC50 / TC147: the withdrawn reasons stay on the timeline, the active state does not.
        $this->assertSame(1, DpcEvaluationRecordGap::query()->count());
        $withdrawn = ActivityTimeline::query()->where('event', 'dpc_proposal.record_gap_withdrawn')->get();
        $this->assertCount(1, $withdrawn);
        $this->assertSame($this->ao->id, (int) $withdrawn->first()->causer_id, 'TC148: by the AO.');
        $this->assertSame($this->last - 2, (int) $withdrawn->first()->properties['year']);
        $this->assertGreaterThanOrEqual(3, ActivityTimeline::query()->where('event', 'dpc_proposal.record_gap_recorded')->count(), 'TC142: each reason recorded.');
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'dpc_proposal.employee_cr_year_removed')->count(), 'TC145.');
    }

    // ------------------------------------------------ TC51–TC70: Property File

    private function property(int $offset, array $payload): TestResponse
    {
        if ((int) ($payload['missing_reason_id'] ?? 0) === $this->reason('newly_appointed')) {
            $this->joinedIn($this->last - $offset);
        }

        return $this->actingAs($this->ao)->post($this->route('property.save'), ['year' => $this->last - $offset, ...$payload]);
    }

    public function test_the_property_file_is_corrected_by_edit_or_delete_without_touching_the_crs(): void
    {
        app(PromotionCrRules::class)->ruleForProposal($this->proposal)->years()->update(['property_mandatory' => true]);
        $this->apply([['G', 0], ['G', 1], ['G', 2], ['G', 3], ['G', 4]]);
        $crBefore = DpcEvaluationCrGrade::query()->orderBy('year')->get(['year', 'grade', 'put_up'])->toArray();
        $submitted = ['submitted' => '1', 'submitted_on' => ($this->last - 1).'-01-15', 'next_increment_date' => ($this->last - 1).'-07-01'];

        // TC51 available; TC52 missing.
        $this->property(1, ['submitted' => '1', 'submitted_on' => $this->last.'-01-15', 'next_increment_date' => $this->last.'-07-01'])->assertSessionHasNoErrors();
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('record_not_available'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertSame(1, DpcEvaluationRecordGap::query()->where('kind', 'property')->count());

        // TC58: marked "appointed in this year" by mistake: it limits the CR years asked too.
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('newly_appointed'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertSame($this->last - 2, $this->row->fresh()->appointedYear());

        // TC59: edited to another reason — the limit goes.
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('record_lost'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear());

        // TC60: marked appointed again, then edited to submitted — its reason withdrawn.
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('newly_appointed'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->property(2, $submitted)->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear());
        $this->assertSame(0, DpcEvaluationRecordGap::query()->where('kind', 'property')->count(), 'TC70: none left active.');

        // TC61 / TC62: appointed again, then deleted — limit and reason gone, the year offered again, recreated.
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('newly_appointed'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->delete($this->route('property.remove', [$this->last - 2]))->assertSessionHasNoErrors();
        $this->assertNull($this->row->fresh()->appointedYear());
        $this->assertContains($this->last - 2, $this->yearList('property-year'), 'TC69: offered again.');
        $this->property(2, $submitted)->assertSessionHasNoErrors();

        // TC63: changed back and forth.
        foreach ([['submitted' => '0', 'missing_reason_id' => $this->reason('record_not_available'), 'missing_document' => $this->pdf()], $submitted] as $change) {
            $this->property(2, $change)->assertSessionHasNoErrors();
        }

        // TC64: the CR records are exactly as they were.
        $this->assertSame($crBefore, DpcEvaluationCrGrade::query()->orderBy('year')->get(['year', 'grade', 'put_up'])->toArray());
        $this->assertNoDuplicates();

        // TC68 / TC146: the Property File's trail.
        $this->assertGreaterThanOrEqual(1, ActivityTimeline::query()->where('event', 'dpc_proposal.employee_property_year_removed')->count());
        $this->assertGreaterThanOrEqual(2, ActivityTimeline::query()->where('event', 'dpc_proposal.record_gap_withdrawn')->where('properties->kind', 'property')->count());
    }

    /** TC65: a CR corrected leaves the Property Files alone. */
    public function test_a_cr_correction_leaves_the_property_files_alone(): void
    {
        app(PromotionCrRules::class)->ruleForProposal($this->proposal)->years()->update(['property_mandatory' => true]);
        $this->property(2, ['submitted' => '0', 'missing_reason_id' => $this->reason('record_not_available'), 'missing_document' => $this->pdf()])->assertSessionHasNoErrors();
        $this->property(1, ['submitted' => '1', 'submitted_on' => $this->last.'-01-15', 'next_increment_date' => $this->last.'-07-01'])->assertSessionHasNoErrors();
        $before = [DpcEvaluationPropertyFlag::query()->orderBy('year')->get(['year', 'flag'])->toArray(), DpcEvaluationRecordGap::query()->where('kind', 'property')->pluck('year')->all()];

        $this->apply([['M', 2], ['G', 2], ['A', 3]]);
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 3]))->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last - 2]))->assertSessionHasNoErrors();

        $this->assertSame($before, [DpcEvaluationPropertyFlag::query()->orderBy('year')->get(['year', 'flag'])->toArray(), DpcEvaluationRecordGap::query()->where('kind', 'property')->pluck('year')->all()]);
    }

    // ------------------------------------------------ TC121–TC140: security

    public function test_nobody_else_edits_or_deletes_and_validation_holds(): void
    {
        $this->apply([['G', 0], ['M', 2]]);
        $before = [DpcEvaluationCrGrade::query()->count(), DpcEvaluationRecordGap::query()->count()];

        // TC121–TC126: an AO of another department, and an AO of this one not allotted.
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        foreach ([User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create(), User::factory()->assignedTo($this->proposal->department_id, Role::AuthorizedOfficer)->create()] as $stranger) {
            $this->actingAs($stranger)->post($this->route('cr.save'), ['year' => $this->last - 1, 'put_up' => '1', 'has_grading' => '0'])->assertForbidden();
            $this->actingAs($stranger)->delete($this->route('cr.remove', [$this->last - 2]))->assertForbidden();
            $this->actingAs($stranger)->post($this->route('property.save'), ['year' => $this->last - 1, 'submitted' => '1', 'submitted_on' => $this->last.'-01-15', 'next_increment_date' => $this->last.'-07-01'])->assertForbidden();
            $this->actingAs($stranger)->delete($this->route('property.remove', [$this->last - 1]))->assertForbidden();
        }

        // TC137: no session, no write.
        auth()->logout();
        $this->post($this->route('cr.save'), ['year' => $this->last - 1, 'put_up' => '1', 'has_grading' => '0'])->assertRedirect();
        $this->assertSame($before, [DpcEvaluationCrGrade::query()->count(), DpcEvaluationRecordGap::query()->count()]);

        // TC133 / TC135 / TC136: an unknown reason, an impossible year, a crafted field.
        $this->step('M', 3, ['missing_reason_id' => 999999])->assertSessionHasErrors('missing_reason_id');
        $this->step('G', -1)->assertSessionHasErrors();
        $this->step('G', 1, ['dpc_evaluation_row_id' => 999999, 'year' => 'abc'])->assertSessionHasErrors('year');
        // TC134: a reason asking for remarks, given none, on the first save and again.
        $this->step('M', 3, ['missing_reason_id' => $this->reason('other')])->assertSessionHasErrors('missing_remarks');
        $this->assertSame($before, [DpcEvaluationCrGrade::query()->count(), DpcEvaluationRecordGap::query()->count()]);

        // TC138: the missing-record document is read by the department's own officers only.
        $gap = DpcEvaluationRecordGap::query()->sole();
        $this->actingAs(User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create())
            ->get(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row, $gap]))->assertForbidden();
        $this->actingAs($this->ao)->get(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row, $gap]))->assertOk();
    }

    /** TC108: after submission the AO no longer edits. */
    public function test_after_submission_the_ao_no_longer_edits(): void
    {
        $this->apply([['G', 0]]);
        $this->proposal->forceFill(['status' => DpcProposalStatus::Submitted->value])->save();

        $this->step('G', 1)->assertForbidden();
        $this->actingAs($this->ao)->delete($this->route('cr.remove', [$this->last]))->assertForbidden();
        $this->assertSame(1, DpcEvaluationCrGrade::query()->count());
    }
}
