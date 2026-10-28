<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Console\Commands\ResetTransactionalDataCommand;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * A fresh installation — every migration, then `db:seed` — holds required master
 * and reference data only (business decision, 2026-10-26): no account, no post,
 * no workflow record and no audit row. The department master is seeded, and its
 * departments wait unregistered for an invitation.
 */
class FreshInstallationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TestCase seats a Chairman for every test; a fresh installation is
        // asserted without that fixture.
        CgpscChairman::query()->delete();
        DB::table('activity_timelines')->delete();
        DB::table('audits')->delete();

        // `db:seed` runs with the shipped console-auditing setting (off;
        // config/audit.php), not phpunit.xml's AUDIT_CONSOLE=true. The activity
        // timeline has no such switch, so it is the seeders themselves that must
        // record no event.
        config(['audit.console' => false]);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_no_account_is_seeded_and_the_department_waits_unregistered(): void
    {
        $this->assertSame(0, DB::table('users')->count(), 'The Portal Super Admin comes from dpc:bootstrap-super-admin.');
        $departments = DB::table('departments')->get(['code', 'name', 'status']);
        $this->assertCount(48, $departments, 'The departments of the Government of Chhattisgarh.');
        $this->assertSame([DepartmentStatus::Unregistered->value], $departments->pluck('status')->unique()->values()->all());
        $this->assertSame($departments->count(), $departments->pluck('code')->unique()->count());
        $this->assertSame($departments->count(), $departments->pluck('name')->unique()->count());
        $this->assertTrue($departments->every(fn ($d): bool => (bool) preg_match('/^[A-Z0-9]{1,20}$/', $d->code)), 'Each code is a valid DPC ID prefix.');
        $this->assertSame('School Education Department', $departments->firstWhere('code', 'SE')?->name);

        // Ready to invite: district, place, PIN and the placeholder e-mail are set.
        $this->assertTrue(Department::query()->get()->every(fn (Department $d): bool => $d->hasRegistrationDetails()));
        $se = Department::query()->where('code', 'SE')->sole();
        $this->assertSame('Raipur', $se->district?->name_en);
        $this->assertSame('492002', $se->pin);
        $this->assertSame('se.dpc@cg.gov.in', $se->email);
        $this->assertSame(0, DB::table('department_posts')->count());
        $this->assertSame(0, DB::table('user_department_assignments')->count());
    }

    /**
     * A re-run adds what is missing and never overwrites the Administrator's entry:
     * a renamed department keeps its name, a retired one stays retired, and a name
     * the Administrator gave another code is not seeded twice.
     */
    public function test_reseeding_departments_keeps_the_administrators_changes(): void
    {
        Department::query()->where('code', 'SE')->sole()->update(['name' => 'School Education Department, Chhattisgarh', 'email' => 'secy.school@cg.gov.in', 'place' => null]);
        Department::query()->where('code', 'FIN')->sole()->delete();
        Department::query()->where('code', 'PWD')->sole()->forceDelete();
        Department::query()->create(['code' => 'LOKNIRMAN', 'name' => 'Public Works Department', 'status' => DepartmentStatus::Unregistered->value]);

        $this->seed(DepartmentMasterSeeder::class);

        $se = Department::query()->where('code', 'SE')->sole();
        $this->assertSame('School Education Department, Chhattisgarh', $se->name);
        $this->assertSame('secy.school@cg.gov.in', $se->email, 'An entered e-mail is never overwritten.');
        $this->assertSame('Mahanadi Bhawan, Mantralaya, Nava Raipur Atal Nagar', $se->place, 'Only a blank field is filled.');
        $this->assertTrue(Department::withTrashed()->where('code', 'FIN')->sole()->trashed());
        $this->assertFalse(Department::withTrashed()->where('code', 'PWD')->exists());
        $this->assertSame(48, Department::withTrashed()->count());
    }

    public function test_every_transactional_table_is_empty(): void
    {
        $transactional = (new ReflectionClass(ResetTransactionalDataCommand::class))->getConstant('TRANSACTIONAL');

        // Every transactional table — the activity timeline and the audit trail
        // included — is empty: seeding master data records no workflow event.
        foreach ($transactional as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty on a fresh installation.");
        }

        foreach (['recruitment_rules', 'promotion_cr_rules', 'reservation_rosters'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} is entered by the Administrator or generated, not seeded.");
        }
    }

    public function test_the_required_master_data_is_present(): void
    {
        foreach (['roles', 'districts', 'designations', 'pay_scales', 'qualifications', 'service_classes', 'cr_grade_values',
            'record_reasons', 'sealed_cover_reasons', 'reservation_categories', 'reservation_policies',
            'committee_configurations', 'review_reasons', 'vacancy_types', 'dpc_types'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} must be seeded.");
        }

        $this->assertTrue(DB::table('roles')->where('name', 'department_admin')->exists());
        $chairman = CgpscChairman::query()->serving()->sole();
        $this->assertNull($chairman->user_id, 'The Chairman record, without an account.');
    }
}
