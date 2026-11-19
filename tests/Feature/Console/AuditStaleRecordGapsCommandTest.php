<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\AuditStaleRecordGapsCommand;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The read-only audit of missing reasons left active by the correction defect fixed
 * on 2026-11-19: what it flags, what it leaves out, and that it changes nothing.
 */
class AuditStaleRecordGapsCommandTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake('local');

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Under Secretary'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());
        $this->row = $this->proposal->evaluationRows()->sole();
    }

    private function gap(string $kind, int $year, string $reason): DpcEvaluationRecordGap
    {
        $path = "gaps/{$kind}-{$year}.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 evidence');

        return $this->row->recordGaps()->create([
            'kind' => $kind, 'year' => $year, 'record_reason_id' => RecordReason::query()->where('code', $reason)->value('id'),
            'remarks' => $reason === 'other' ? 'Remarks' : null,
            'disk' => 'local', 'path' => $path, 'original_name' => "{$kind}-{$year}.pdf", 'size_bytes' => 18, 'sha256' => str_repeat('c', 64),
        ]);
    }

    /** The state the defect left behind, written as it was — not through today's services. */
    private function arrangeTheDefectsLeftovers(): void
    {
        // An Edit to "put up" left the reason (2024).
        $this->row->crGrades()->create(['year' => 2024, 'grade' => 'क', 'put_up' => true, 'has_grading' => true]);
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2024, 'long_leave');

        // A year still recorded missing: its reason is its own (2023) — not reported.
        $this->row->crGrades()->create(['year' => 2023, 'put_up' => false]);
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2023, 'record_lost');

        // "Appointed in this year" chosen by mistake, the year then deleted (2022).
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2022, 'newly_appointed');
        $this->travel(1)->minutes();
        app(ActivityTimelineService::class)->record('dpc_proposal.employee_cr_year_removed', $this->proposal, 'CR 2022 removed', ['sn' => 1, 'employee_number' => 'E-1', 'year' => 2022], $this->ao);

        // A reason with no year record and no removal on the timeline (2019): review.
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2019, 'record_not_available');

        // A Property File edited to "submitted", its reason left (2023).
        $this->row->propertyFlags()->create(['year' => 2023, 'flag' => true, 'submitted_on' => '2024-01-15']);
        $this->gap(DpcEvaluationRecordGap::KIND_PROPERTY, 2023, 'record_not_available');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'gaps' => DB::table('dpc_evaluation_record_gaps')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'cr' => DB::table('dpc_evaluation_cr_grades')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'property' => DB::table('dpc_evaluation_property_flags')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'timeline' => ActivityTimeline::query()->count(),
            'files' => Storage::disk('local')->allFiles(),
        ];
    }

    public function test_it_finds_the_leftovers_apart_from_the_possible_false_positives_and_changes_nothing(): void
    {
        $this->arrangeTheDefectsLeftovers();
        $before = $this->snapshot();
        $csv = sys_get_temp_dir().'/stale-gaps-'.uniqid().'.csv';

        $this->artisan('dpc:audit-stale-record-gaps', ['--csv' => $csv])
            ->expectsOutputToContain('Read-only audit')
            ->expectsOutputToContain('"Appointed in this year" setting an officer\'s boundary: 1')
            ->assertSuccessful();

        // Nothing written, deleted or repaired; every document still there.
        $this->assertSame($before, $this->snapshot());

        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($csv))));
        @unlink($csv);
        $header = array_shift($rows);
        $found = array_map(fn (array $r): array => array_combine($header, $r), $rows);
        $by = fn (string $record, string $year): ?array => collect($found)->first(fn (array $f): bool => $f['Record'] === $record && $f['Year'] === $year);

        $this->assertCount(4, $found, 'The year still recorded missing is not reported.');
        $this->assertSame(AuditStaleRecordGapsCommand::STALE_AVAILABLE, $by('CR', '2024')['Classification']);
        $this->assertSame(AuditStaleRecordGapsCommand::STALE_AVAILABLE, $by('Property File', '2023')['Classification']);
        $this->assertSame(AuditStaleRecordGapsCommand::REVIEW, $by('CR', '2019')['Classification']);
        $this->assertNull($by('CR', '2023'));

        $appointed = $by('CR', '2022');
        $this->assertSame(AuditStaleRecordGapsCommand::ORPHAN_REMOVED, $appointed['Classification']);
        $this->assertSame(['E-1', 'newly_appointed', 'yes', '2022', 'yes', 'none'], [
            $appointed['Employee ID'], $appointed['Reason'], $appointed['Appointment reason'], $appointed['Officer appointment boundary'], $appointed['Sets the boundary'], $appointed['Record row'],
        ]);
        $this->assertNotSame('—', $appointed['Removed at']);

        // Suspicious first, the possible false positive last.
        $this->assertSame(AuditStaleRecordGapsCommand::REVIEW, end($found)['Classification']);
    }

    public function test_a_removal_before_the_reason_was_recorded_is_not_taken_as_its_cause(): void
    {
        app(ActivityTimelineService::class)->record('dpc_proposal.employee_cr_year_removed', $this->proposal, 'CR 2021 removed', ['sn' => 1, 'year' => 2021], $this->ao);
        $this->travel(1)->minutes();
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2021, 'record_lost');
        $csv = sys_get_temp_dir().'/stale-gaps-'.uniqid().'.csv';

        $this->artisan('dpc:audit-stale-record-gaps', ['--csv' => $csv])->assertSuccessful();

        $this->assertStringContainsString(AuditStaleRecordGapsCommand::REVIEW, (string) file_get_contents($csv));
        $this->assertStringNotContainsString(AuditStaleRecordGapsCommand::ORPHAN_REMOVED, (string) file_get_contents($csv));
        @unlink($csv);
    }

    public function test_a_clean_database_reports_nothing_and_a_dpc_can_be_chosen(): void
    {
        // Only a year recorded missing with its own reason: nothing to report.
        $this->row->crGrades()->create(['year' => 2018, 'put_up' => false]);
        $this->gap(DpcEvaluationRecordGap::KIND_CR, 2018, 'record_lost');

        $this->artisan('dpc:audit-stale-record-gaps')
            ->expectsOutputToContain(AuditStaleRecordGapsCommand::STALE_AVAILABLE.' | 0 ')
            ->expectsOutputToContain(AuditStaleRecordGapsCommand::ORPHAN_REMOVED.'    | 0 ')
            ->expectsOutputToContain(AuditStaleRecordGapsCommand::REVIEW.'       | 0 ')
            ->expectsOutputToContain('"Appointed in this year" setting an officer\'s boundary: 0')
            ->assertSuccessful();

        $this->arrangeTheDefectsLeftovers();
        $this->artisan('dpc:audit-stale-record-gaps', ['--proposal' => (string) $this->proposal->id])->assertSuccessful();
        $this->artisan('dpc:audit-stale-record-gaps', ['--proposal' => 'NO-SUCH-DPC'])->expectsOutputToContain('No DPC [NO-SUCH-DPC]')->assertFailed();
    }

    /** The same leftovers, many officers: a few queries per batch, not per reason. */
    public function test_it_reads_in_batches(): void
    {
        $this->arrangeTheDefectsLeftovers();
        DB::enableQueryLog();

        $this->artisan('dpc:audit-stale-record-gaps', ['--chunk' => 50])->assertSuccessful();

        $writes = array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/^\s*(insert|update|delete|truncate|alter|drop)/i', $q['query']) === 1);
        $this->assertSame([], array_values($writes), 'Not one write.');
        $this->assertLessThan(20, count(DB::getQueryLog()));
    }
}
