<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\ProposalVacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Employee\Models\Employee;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The simple Working Officers List (2026-11-01) of a Vacancy Management proposal:
 * SN, EMPLOYEE NO, NAME, DOB, MOBILE, EMAIL (2026-11-02), HERE ON DEPUTATION, OUT ON DEPUTATION —
 * each officer identified by employee number, and every deputation row matched with
 * its officer in the finalized record's B1 or B2.
 *
 * The record: sanctioned 10; 6 of our officers (one of them, E. Away, out on deputation
 * since 2024 — B2, matched by number); one officer here on deputation (D. Visitor — B1,
 * matched by name). Working 7.
 */
class WorkingListDeputationImportTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const HEADER = ['SN', 'EMPLOYEE NO', 'NAME', 'DOB', 'MOBILE', 'EMAIL', 'HERE ON DEPUTATION', 'OUT ON DEPUTATION'];

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        Storage::fake((string) config('dpc.imports.disk', 'local'));
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'registered']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = $this->makePost($department, 'Block Education Officer', 'II');
        $to = $this->makePost($department, 'Additional Director, Education', 'I');

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);

        $service = app(VacancyMasterService::class);
        $pdf = fn (): UploadedFile => UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
        $position = $service->openDraft($department, $to, 2026, $this->ao);
        // Our six, E-6 away on deputation (B2) among them, and the visitor here (B1):
        // the working strength includes both (2026-11-04).
        $service->saveStrength($position, ['sanctioned_total' => 10, 'working_total' => 7, 'order_number' => 'SANC/1', 'order_date' => '2026-01-02'], $pdf(), $this->ao);
        $service->addEntry($position->fresh(), ['kind' => 'deputation_in', 'officer_name' => 'Shri D. Visitor', 'deputation_from' => '2025-01-01', 'deputation_to' => '2026-09-30'], null, $this->ao);
        $service->addEntry($position->fresh(), ['kind' => 'deputation', 'officer_name' => 'E. Away', 'employee_number' => 'E-6', 'deputation_from' => '2024-07-01', 'promotion_made' => '0'], null, $this->ao);
        $service->finalize($position->fresh(), $this->ao);

        // B2 is our officer, so Vacancy Management already put them in the employee
        // database; B1 belongs to another department and is not recorded.
        $this->assertSame(['E-6'], Employee::query()->pluck('employee_number')->all());
    }

    private function makePost(Department $department, string $name, string $class): DepartmentPost
    {
        $post = new DepartmentPost(['department_id' => $department->id, 'name' => $name, 'class_level' => $class, 'effective_from' => '2020-01-01']);
        $post->status = MasterStatus::Active;
        $post->active_flag = 1;
        $post->save();

        return $post;
    }

    /**
     * The list that agrees with the record, with any row replaced.
     *
     * @param  array<int, list<string>>  $replace  sn => row
     * @return list<list<string>>
     */
    private function rows(array $replace = []): array
    {
        $rows = [];

        foreach (range(1, 7) as $sn) {
            $rows[$sn] = match ($sn) {
                6 => ['6', 'E-6', 'Smt. E. Away', '01-01-1975', '', 'N', 'Y'],
                7 => ['7', 'OD-77', 'D. Visitor', '01-01-1976', '', 'Y', 'N'],
                default => [(string) $sn, 'E-'.$sn, 'Officer '.$sn, '01-01-1975', '9425012345', 'N', 'N'],
            };
        }

        return array_values(array_replace($rows, $replace));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function preview(array $rows): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'wl').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADER);
        foreach ($rows as $row) {
            // The tests write rows without EMAIL; it sits after MOBILE.
            if (count($row) === 7) {
                array_splice($row, 5, 0, [$row[0] === '1' ? 'officer1@example.gov.in' : '']);
            }
            fputcsv($handle, $row);
        }
        fclose($handle);

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.store', $this->proposal), [
            'template' => 'currently_working_us',
            'file' => new UploadedFile($path, 'working.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<string> every message on the row
     */
    private function errorsOf(ImportBatch $batch, int $line): array
    {
        $row = $batch->rows()->where('row_number', $line)->firstOrFail();

        return array_merge(...array_values((array) $row->errors));
    }

    public function test_the_template_has_the_simple_columns(): void
    {
        $csv = $this->actingAs($this->ao)->get(route('dpc-import-templates.download-for', [$this->proposal, 'currently_working_us']))->assertOk()->getContent();

        $this->assertStringContainsString('SN,"EMPLOYEE NO",NAME,DOB,MOBILE,EMAIL,"HERE ON DEPUTATION","OUT ON DEPUTATION"', (string) $csv);
    }

    public function test_a_list_that_agrees_with_b1_and_b2_imports_and_reconciles(): void
    {
        $batch = $this->preview($this->rows());
        $this->assertSame(0, (int) $batch->rejected_count);

        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))->assertSessionHasNoErrors();

        $away = $this->proposal->workingOfficers()->where('employee_number', 'E-6')->sole();
        $this->assertTrue($away->out_on_deputation);
        $this->assertSame(DpcWorkingOfficer::SOURCE_DEPUTATION, $this->proposal->workingOfficers()->where('employee_number', 'OD-77')->sole()->source);

        // The employee database (2026-11-02): our six officers by Employee ID, with the
        // list's details; not the officer here on deputation from another department.
        $this->assertSame(['E-1', 'E-2', 'E-3', 'E-4', 'E-5', 'E-6'], Employee::query()->orderBy('employee_number')->pluck('employee_number')->all());
        $away = Employee::query()->where('employee_number', 'E-6')->sole();
        $this->assertSame(['Smt. E. Away', Employee::SOURCE_WORKING_LIST, $this->proposal->department_id], [$away->name, $away->source, $away->department_id]);
        $this->assertSame('officer1@example.gov.in', Employee::query()->where('employee_number', 'E-1')->value('email'));

        $reconciliation = app(ProposalVacancyPosition::class)->reconcile($this->proposal->refresh());
        $this->assertSame(['deputation', 'deputation_out', 'total'], $reconciliation['figures']);
        $this->assertSame([1, 1, 7], [$reconciliation['uploaded']['deputation'], $reconciliation['uploaded']['deputation_out'], $reconciliation['uploaded']['total']]);
        $this->assertSame([1, 1, 7], [$reconciliation['record']['deputation'], $reconciliation['record']['deputation_out'], $reconciliation['record']['total']]);
        $this->assertTrue($reconciliation['matches'], json_encode($reconciliation));
    }

    public function test_invalid_rows_are_refused_with_the_reason(): void
    {
        $batch = $this->preview($this->rows([
            1 => ['1', '', 'Officer 1', '01-01-1975', '', 'N', 'N'],          // no employee number
            2 => ['2', 'E-2', 'Officer 2', '31-02-1975', '', 'N', 'N'],       // no such date
            3 => ['3', 'E-3', 'Officer 3', '01-01-1975', '', 'maybe', 'N'],   // not Y or N
            4 => ['4', 'E-4', 'Officer 4', '01-01-1975', '', 'Y', 'Y'],       // both
            5 => ['5', 'E-5', '', '01-01-1975', '', 'N', 'N'],                // no name
            6 => ['6', 'E-6', 'Smt. E. Away', '01-01-1975', '', 'not-an-email', 'N', 'Y'], // bad email
        ]));

        $this->assertStringContainsString('EMPLOYEE NO is required', implode(' ', $this->errorsOf($batch, 2)));
        $this->assertNotSame([], $this->errorsOf($batch, 3), 'An impossible date.');
        $this->assertStringContainsString('HERE ON DEPUTATION: expected Y or N.', implode(' ', $this->errorsOf($batch, 4)));
        $this->assertStringContainsString('cannot be both HERE ON DEPUTATION and OUT ON DEPUTATION', implode(' ', $this->errorsOf($batch, 5)));
        $this->assertNotSame([], $this->errorsOf($batch, 6), 'A name is required.');
        $this->assertArrayHasKey('email', (array) $batch->rows()->where('row_number', 7)->firstOrFail()->errors);
        $this->assertSame(6, (int) $batch->rejected_count);
    }

    public function test_a_duplicate_employee_number_is_refused_on_every_row_that_carries_it(): void
    {
        $batch = $this->preview($this->rows([2 => ['2', 'E-1', 'Officer 2', '01-01-1975', '', 'N', 'N']]));

        $this->assertStringContainsString('appears more than once', implode(' ', $this->errorsOf($batch, 2)));
        $this->assertStringContainsString('appears more than once', implode(' ', $this->errorsOf($batch, 3)));
    }

    public function test_deputation_rows_must_match_b1_and_b2(): void
    {
        $batch = $this->preview($this->rows([
            // B2 officer not marked OUT.
            6 => ['6', 'E-6', 'E. Away', '01-01-1975', '', 'N', 'N'],
            // Marked HERE, but nobody of that number or name is in B1.
            1 => ['1', 'E-1', 'Officer 1', '01-01-1975', '', 'Y', 'N'],
            // The B1 officer marked OUT: the wrong side.
            7 => ['7', 'OD-77', 'D. Visitor', '01-01-1976', '', 'N', 'Y'],
        ]));

        $this->assertStringContainsString(__('vacancymaster.proposal.working_list.match.not_marked', ['side' => 'B2', 'name' => 'E. Away', 'column' => 'OUT ON DEPUTATION']), implode(' ', $this->errorsOf($batch, 7)));
        $this->assertStringContainsString(__('vacancymaster.proposal.working_list.match.not_recorded', ['side' => 'B1']), implode(' ', $this->errorsOf($batch, 2)));
        $this->assertStringContainsString(__('vacancymaster.proposal.working_list.match.other_side', ['side' => 'B1', 'name' => 'Shri D. Visitor']), implode(' ', $this->errorsOf($batch, 8)));
    }

    public function test_the_same_officer_twice_is_refused_and_a_b1_officer_left_out_blocks_the_reconciliation(): void
    {
        // D. Visitor on two rows, under two numbers: the second is refused.
        $batch = $this->preview([...$this->rows(), ['8', 'OD-78', 'D. Visitor', '01-01-1976', '', 'Y', 'N']]);
        $this->assertStringContainsString(__('vacancymaster.proposal.working_list.match.twice', ['side' => 'B1', 'name' => 'Shri D. Visitor']), implode(' ', $this->errorsOf($batch, 9)));

        // A list without the B1 officer: imported, but it does not reconcile, and says who is missing.
        $batch = $this->preview(array_slice($this->rows(), 0, 6));
        $this->assertSame(0, (int) $batch->rejected_count);
        $this->actingAs($this->ao)->post(route('dpc-proposals.imports.commit', [$this->proposal, $batch]))->assertSessionHasNoErrors();

        $reconciliation = app(ProposalVacancyPosition::class)->reconcile($this->proposal->refresh());
        $this->assertFalse($reconciliation['matches']);
        $this->assertSame([['kind' => 'B1', 'name' => 'Shri D. Visitor']], $reconciliation['missing']);

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))
            ->assertOk()
            ->assertSee('id="working-list-unnamed"', false)
            ->assertSee('Shri D. Visitor');
    }

    public function test_the_b1_and_b2_eligibility_rules_are_the_records_and_unchanged(): void
    {
        $breakdown = app(ProposalVacancyPosition::class)->for($this->proposal)->breakdown;

        // B1 ends 30-09-2026, inside the year: counted. B2 away since 01-07-2024 — over a
        // year by 31-12-2026, no promotion made: counted.
        $this->assertSame([1, 1], [$breakdown['deputation_in_vacancy'], $breakdown['deputation_out_vacancy']]);
    }
}
