<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ResetTransactionalDataCommand;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * `dpc:reset-transactional-data` — a fresh installation with respect to workflow
 * data, and nothing else touched.
 *
 * **The test that matters most is `test_every_table_in_the_schema_is_classified`.**
 * A table added later and named in neither list would otherwise be silently kept or
 * silently destroyed, and which of those it was would be discovered by somebody
 * losing data.
 */
class ResetTransactionalDataTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();

        $this->raiseDpc($this->ao, $this->departmentPost('Under Secretary', 5), $this->departmentPost('Deputy Secretary'));
    }

    private function departmentPost(string $name, ?int $duration = null): DepartmentPost
    {
        return DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => $name,
            'class_level' => 'I',
            'promotion_duration_years' => $duration,
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Active->value,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listed(string $constant): array
    {
        /** @var list<string> $value */
        $value = (new ReflectionClass(ResetTransactionalDataCommand::class))->getConstant($constant);

        return $value;
    }

    public function test_it_clears_the_workflow_and_keeps_the_masters_and_the_people(): void
    {
        $this->assertGreaterThan(0, DpcProposal::query()->count());

        $users = User::query()->count();
        $posts = DepartmentPost::query()->count();
        $assignments = DB::table('user_department_assignments')->count();
        $roles = DB::table('roles')->count();
        $grants = DB::table('model_has_roles')->count();

        $this->artisan('dpc:reset-transactional-data', ['--force' => true])->assertSuccessful();

        // Gone.
        $this->assertSame(0, DpcProposal::query()->count());
        $this->assertSame(0, PreDpcProposal::query()->count());
        $this->assertSame(0, DB::table('workflow_transitions')->count());
        $this->assertSame(0, DB::table('activity_timelines')->count());
        $this->assertSame(0, DB::table('dpc_id_sequences')->count());

        // Untouched.
        $this->assertSame($users, User::query()->count(), 'No user account may be removed.');
        $this->assertSame($posts, DepartmentPost::query()->count());
        $this->assertSame($assignments, DB::table('user_department_assignments')->count());
        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame($grants, DB::table('model_has_roles')->count(), 'Role grants are part of the account.');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $before = DpcProposal::query()->count();

        $this->artisan('dpc:reset-transactional-data', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, DpcProposal::query()->count());
    }

    /**
     * A table in neither list is a table nobody has decided about.
     */
    public function test_every_table_in_the_schema_is_classified(): void
    {
        $known = array_merge($this->listed('TRANSACTIONAL'), $this->listed('PRESERVED'));

        /*
         | Through the schema builder, and **without a schema argument**.
         |
         | This test was vacuous until 2026-08-27. It passed
         | `DB::connection()->getDatabaseName()` as the schema, which is `:memory:`
         | on the SQLite connection the suite runs on — and no table lives in a
         | schema called `:memory:`, so the listing came back **empty** and
         | `array_diff([], $known)` was empty too. The assertion could not fail, and
         | it duly did not when `dpc_evaluation_record_gaps` and `record_reasons`
         | were added unclassified. The command's own runtime guard caught them
         | instead, which is the wrong place for a test to be doing its job.
         |
         | Every table on the connection, unqualified. `schemaQualified: false`
         | still matters — without it SQLite returns `main.audits` and MySQL returns
         | a bare `audits`, and the comparison would fail on one driver only.
         */
        $tables = Schema::getTableListing(schemaQualified: false);

        /*
         | And a floor, so this can never be vacuous again.
         |
         | The exact count is not the point and would be a maintenance burden; that
         | the listing returned *something like a whole schema* is. A listing that
         | silently returns nothing is the only way the assertion below passes
         | without having checked anything.
         */
        $this->assertGreaterThan(
            50,
            count($tables),
            'The schema listing returned almost nothing, so the check below proves nothing. '
            .'Fix the listing before trusting this test.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($tables, $known)),
            'Every table must be listed as transactional or preserved. Add the new one to the right list.',
        );
    }

    /**
     * The two lists must not overlap either — a table in both would be preserved
     * by intention and destroyed in fact.
     */
    public function test_no_table_is_in_both_lists(): void
    {
        $this->assertSame(
            [],
            array_values(array_intersect($this->listed('TRANSACTIONAL'), $this->listed('PRESERVED'))),
        );
    }

    public function test_it_refuses_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('dpc:reset-transactional-data', ['--force' => true])->assertFailed();

        $this->assertGreaterThan(0, DpcProposal::query()->count(), 'Nothing may be cleared in production.');
    }

    public function test_the_evidence_tables_are_cleared_and_that_is_deliberate(): void
    {
        // Not an oversight: the audit trail and the signed-document register are
        // records *of the workflow*, and a reset that kept them would leave a
        // "fresh" installation citing DPCs that no longer exist.
        $transactional = $this->listed('TRANSACTIONAL');

        // The config is keyed *by* table name; its values are the permitted-update
        // column lists.
        foreach (array_keys((array) config('dpc.security.evidence_tables', [])) as $table) {
            $this->assertContains((string) $table, $transactional, "{$table} is evidence and must be cleared by a reset.");
        }

        $this->assertTrue(Schema::hasTable('audits'));
        $this->assertContains('audits', $transactional);
    }

    /**
     * The masters the register names are all on the preserved list, by name.
     */
    public function test_the_masters_the_register_names_are_preserved(): void
    {
        $preserved = $this->listed('PRESERVED');

        foreach ([
            'departments', 'department_posts', 'pay_scales', 'service_classes', 'recruitment_rules',
            'users', 'user_department_assignments', 'roles', 'permissions',

            /*
             | Added by the change register of 2026-08-26. `commission_members` left
             | this list on the same date because item 5 withdrew the master
             | entirely — a table that no longer exists cannot be preserved.
             |
             | `cgpsc_chairmen` matters most of the three: item 9 makes the seat
             | mandatory on every committee, so clearing it would leave the portal
             | unable to compose a single one.
             */
            'cgpsc_chairmen',
            'department_post_orders',
            'rr_source_qualifications',
        ] as $table) {
            $this->assertContains($table, $preserved);
        }
    }
}
