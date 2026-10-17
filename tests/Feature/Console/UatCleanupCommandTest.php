<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * `dpc:uat-cleanup` — every DPC record gone, every master kept, and only the accounts
 * of the preserved roles (CGPSC Administrator and Chairman) left.
 */
class UatCleanupCommandTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    private User $chairman;

    private User $ao;

    private Department $department;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
        $this->chairman = User::factory()->create();
        $this->chairman->assignRole(Role::Chairman->value);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        User::factory()->create()->assignRole(Role::Member->value);

        $this->raiseDpc($this->ao, $this->departmentPost('Under Secretary', 5), $this->departmentPost('Deputy Secretary'));

        $this->backup = tempnam(sys_get_temp_dir(), 'uat-cleanup-test');
        file_put_contents($this->backup, $this->dump(DB::connection()->getDatabaseName()));
    }

    protected function tearDown(): void
    {
        @unlink($this->backup);
        parent::tearDown();
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

    private function dump(string $database, bool $complete = true): string
    {
        return "-- MySQL dump 10.13\n--\n-- Host: localhost    Database: {$database}\n"
            ."CREATE TABLE `users` (\n  `id` bigint\n);\n"
            .($complete ? "-- Dump completed on 2026-10-17 10:00:00\n" : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function applyOptions(array $overrides = []): array
    {
        $database = DB::connection()->getDatabaseName();

        return [
            '--apply' => true,
            '--database' => $database,
            '--confirm' => $database,
            '--backup' => $this->backup,
            '--report' => tempnam(sys_get_temp_dir(), 'uat-cleanup-report'),
            ...$overrides,
        ];
    }

    private function allowHere(): void
    {
        config(['dpc.uat_cleanup.allowed_environments' => [app()->environment()]]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $users = User::query()->count();
        $proposals = DpcProposal::query()->count();

        $this->artisan('dpc:uat-cleanup')
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->assertSuccessful();

        $this->assertSame($users, User::query()->count());
        $this->assertSame($proposals, DpcProposal::query()->count());
        $this->assertGreaterThan(0, $proposals);
    }

    public function test_it_clears_the_dpc_data_and_every_account_but_the_admin_and_the_chairman(): void
    {
        $this->allowHere();
        $departments = DB::table('departments')->count();
        $posts = DB::table('department_posts')->count();
        $roles = DB::table('roles')->count();

        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->assertSuccessful();

        $this->assertSame(0, DpcProposal::query()->count());
        $this->assertSame(0, DB::table('pre_dpc_proposals')->count());
        $this->assertSame(0, DB::table('workflow_transitions')->count());
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->chairman->id], User::query()->pluck('id')->all());
        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin->value));
        $this->assertTrue($this->chairman->fresh()->hasRole(Role::Chairman->value));
        $this->assertSame(0, DB::table('user_department_assignments')->where('user_id', $this->ao->id)->count());

        $this->assertSame($departments, DB::table('departments')->count());
        $this->assertSame($posts, DB::table('department_posts')->count());
        $this->assertSame($roles, DB::table('roles')->count());

        // Repeatable: a second run finds nothing more to delete.
        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->assertSuccessful();
        $this->assertSame(2, User::query()->count());
    }

    public function test_the_files_of_emptied_rows_are_deleted_after_the_commit(): void
    {
        $this->allowHere();
        $disk = (string) config('dpc.imports.disk', 'local');
        Storage::fake($disk);
        Storage::disk($disk)->put('imports/list.xlsx', 'x');

        DB::table('import_batches')->insert([
            'dpc_proposal_id' => DpcProposal::query()->value('id'),
            'template' => 'seniority',
            'original_filename' => 'list.xlsx',
            'stored_path' => 'imports/list.xlsx',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->assertSuccessful();

        Storage::disk($disk)->assertMissing('imports/list.xlsx');
    }

    public function test_apply_is_refused_outside_the_allowed_environments(): void
    {
        config(['dpc.uat_cleanup.allowed_environments' => ['uat']]);

        $this->artisan('dpc:uat-cleanup', $this->applyOptions())
            ->expectsOutputToContain('--apply is allowed only in uat')
            ->assertFailed();

        $this->assertSame(4, User::query()->count());
    }

    public function test_apply_is_refused_in_production_whatever_the_allow_list_says(): void
    {
        config(['dpc.uat_cleanup.allowed_environments' => ['production']]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->assertFailed();

        $this->assertSame(4, User::query()->count());
    }

    public function test_apply_needs_the_right_database_and_confirmation(): void
    {
        $this->allowHere();

        $this->artisan('dpc:uat-cleanup', $this->applyOptions(['--database' => 'dpc_uat']))
            ->expectsOutputToContain('--database must name the connected database')
            ->assertFailed();

        $this->artisan('dpc:uat-cleanup', $this->applyOptions(['--confirm' => 'dpc_uat']))
            ->expectsOutputToContain('confirmation did not match')
            ->assertFailed();

        $this->assertSame(4, User::query()->count());
    }

    public function test_apply_needs_a_recent_complete_backup_of_this_database(): void
    {
        $this->allowHere();

        $this->artisan('dpc:uat-cleanup', $this->applyOptions(['--backup' => '/nonexistent/dump.sql']))->assertFailed();

        file_put_contents($this->backup, $this->dump('another_db'));
        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->expectsOutputToContain('does not name')->assertFailed();

        file_put_contents($this->backup, $this->dump(DB::connection()->getDatabaseName(), complete: false));
        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->expectsOutputToContain('incomplete')->assertFailed();

        file_put_contents($this->backup, $this->dump(DB::connection()->getDatabaseName()));
        touch($this->backup, time() - 2 * 86400);
        $this->artisan('dpc:uat-cleanup', $this->applyOptions())->expectsOutputToContain('older than 24 hours')->assertFailed();

        $this->assertSame(4, User::query()->count());
    }

    public function test_it_refuses_when_no_account_holds_the_admin_role(): void
    {
        $this->admin->removeRole(Role::Admin->value);

        $this->artisan('dpc:uat-cleanup')
            ->expectsOutputToContain('No account holds the admin role')
            ->assertFailed();
    }
}
