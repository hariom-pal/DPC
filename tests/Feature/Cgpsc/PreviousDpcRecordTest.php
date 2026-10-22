<?php

declare(strict_types=1);

namespace Tests\Feature\Cgpsc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreviousDpc\Models\PreviousDpcRecord;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Coder Instructions rule 1.1 (business decisions, 2026-10-21): the Commission's staff
 * (role CGPSC Staff) enter a DPC held offline; the final seniority list and working
 * officers are optional below 50 eligible employees; a department's rule 1.1
 * reconciliation can cite the record.
 */
class PreviousDpcRecordTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $staff;

    private User $ao;

    private Department $department;

    private DepartmentPost $from;

    private DepartmentPost $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::CgpscStaff->value);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->from = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Block Education Officer', 'class_level' => 'II', 'status' => 'active']);
        $this->to = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Additional Director', 'class_level' => 'I', 'status' => 'active']);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    private function record(array $overrides = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->staff)->post(route('previous-dpcs.store'), array_replace([
            'department_id' => $this->department->id,
            'promotion_from_post_id' => $this->from->id,
            'promotion_to_post_id' => $this->to->id,
            'dpc_year' => 2022,
            'held_on' => '2022-11-15',
            'reference' => 'DPC/SE/2022/7',
            'order_number' => 'F-2/2022/PROMO',
            'order_date' => '2022-12-01',
            'eligible_count' => 12,
            'promoted_officers' => "E-11, Shri A. Verma\nSmt. B. Sahu",
            'minutes' => $this->pdf('minutes.pdf'),
        ], $overrides));
    }

    public function test_the_staff_record_a_previous_dpc_with_its_minutes_and_officers(): void
    {
        $this->actingAs($this->staff)->get(route('previous-dpcs.create'))->assertOk();

        $this->record()->assertSessionHasNoErrors();

        $record = PreviousDpcRecord::query()->sole();
        $this->assertSame([
            ['employee_number' => 'E-11', 'name' => 'Shri A. Verma'],
            ['employee_number' => null, 'name' => 'Smt. B. Sahu'],
        ], $record->promoted_officers);
        $this->assertTrue($record->hasFile('minutes'));
        $this->assertFalse($record->hasFile('seniority_list'), 'Optional below 50 eligible employees.');

        $this->actingAs($this->staff)->get(route('previous-dpcs.file', [$record, 'minutes']))->assertOk();
        $this->actingAs($this->staff)->get(route('previous-dpcs.show', $record))->assertOk()->assertSee('Shri A. Verma');
    }

    public function test_fifty_or_more_eligible_employees_need_the_seniority_list_and_working_officers(): void
    {
        $this->record(['eligible_count' => 50])->assertSessionHasErrors(['seniority_list', 'working_officers']);
        $this->assertSame(0, PreviousDpcRecord::query()->count());

        $this->record([
            'eligible_count' => 50,
            'seniority_list' => $this->pdf('seniority.pdf'),
            'working_officers' => $this->pdf('working.pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertTrue(PreviousDpcRecord::query()->sole()->hasFile('working_officers'));
    }

    public function test_only_the_staff_record_and_the_administrator_reads(): void
    {
        $this->record([], $this->ao)->assertForbidden();
        $this->actingAs($this->ao)->get(route('previous-dpcs.index'))->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        $this->actingAs($admin)->get(route('previous-dpcs.index'))->assertOk();
        $this->record([], $admin)->assertForbidden();
    }

    public function test_a_departments_reconciliation_cites_the_staff_record(): void
    {
        $this->record()->assertSessionHasNoErrors();
        $record = PreviousDpcRecord::query()->sole();

        $proposal = $this->raiseDpc($this->ao, $this->from, $this->to);
        $this->assertInstanceOf(DpcProposal::class, $proposal);

        $this->actingAs($this->ao)->get(route('dpc-proposals.verification', $proposal))
            ->assertOk()
            ->assertSee('name="previous_dpc_record_id"', false);

        // Offline, citing the record: no reference, date or minutes typed or uploaded.
        $this->actingAs($this->ao)->post(route('dpc-proposals.verification.store', [$proposal, 'previous_dpc_reconciliation']), [
            'confirm' => '1',
            'previous_dpc_basis' => 'offline',
            'previous_dpc_record_id' => $record->id,
        ])->assertSessionHasNoErrors();

        $verification = $proposal->verifications()->where('item', 'previous_dpc_reconciliation')->sole();
        $this->assertSame([$record->id, 'DPC/SE/2022/7', '2022-11-15', $record->minutes_sha256], [
            $verification->previous_dpc_record_id, $verification->previous_dpc_reference,
            $verification->previous_dpc_held_on?->toDateString(), $verification->sha256,
        ]);
    }
}
