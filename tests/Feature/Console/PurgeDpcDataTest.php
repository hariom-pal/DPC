<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Maintenance\DpcDataPurgePlan;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\DepartmentStatus;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * `dpc:purge-dpc-data` and Admin → Clear DPC data (2026-11-02): every Pre-DPC and DPC
 * record gone; every account, master and non-DPC entry of a shared table kept.
 */
class PurgeDpcDataTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    private User $ao;

    private Department $department;

    private DpcProposal $proposal;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake('local');
        config(['dpc.dpc_data_purge.allowed_environments' => ['testing']]);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();

        $from = $this->makePost('Under Secretary', 5);
        $to = $this->makePost('Deputy Secretary');
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
        $preDpc = PreDpcProposal::query()->sole();

        // What approving the Pre-DPC writes: a signature, the ledger entry citing it,
        // and the Recruitment Rule projected from it — with its order on the disk.
        $signature = DB::table('signatures')->insertGetId(['user_id' => $this->ao->id, 'signable_type' => $preDpc->getMorphClass(), 'signable_id' => $preDpc->id, 'provider' => 'mock', 'signed_disk' => 'local', 'signed_path' => 'private/signatures/pre-dpc.pdf']);
        DB::table('workflow_transitions')->insert(['uuid' => (string) Str::uuid(), 'workflow' => 'pre_dpc', 'subject_type' => $preDpc->getMorphClass(), 'subject_id' => $preDpc->id, 'event' => 'approve', 'to_state' => 'approved', 'occurred_at' => now(), 'signature_id' => $signature]);
        DB::table('recruitment_rules')->insert(['post_id' => $to->id, 'version' => 1, 'government_order_number' => 'GO/1', 'effective_from' => '2026-01-01']);
        Storage::disk('local')->put('private/signatures/pre-dpc.pdf', 'signed');

        // Not DPC: a user's audit entry and a master's order file.
        DB::table('audits')->insert(['event' => 'updated', 'auditable_type' => $this->admin->getMorphClass(), 'auditable_id' => $this->admin->id]);
        DB::table('department_post_orders')->insert(['uuid' => (string) Str::uuid(), 'department_post_id' => $to->id, 'government_order_number' => 'GO/1', 'government_order_date' => '2026-01-01', 'disk' => 'local', 'path' => 'private/post-orders/go.pdf', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('private/post-orders/go.pdf', 'order');

        $this->database = (string) DB::connection()->getDatabaseName();
    }

    private function makePost(string $name, ?int $duration = null): DepartmentPost
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
     * @return array<string, int>
     */
    private function keptCounts(): array
    {
        $counts = [];

        foreach (['users', 'model_has_roles', 'user_department_assignments', 'departments', 'department_posts', 'department_post_orders', 'qualifications', 'reservation_categories', 'record_reasons', 'sealed_cover_reasons'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $counts['user_audits'] = DB::table('audits')->where('auditable_type', $this->admin->getMorphClass())->count();

        return $counts;
    }

    private function assertDpcDataGone(): void
    {
        foreach (array_keys(DpcDataPurgePlan::TABLES) as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} still holds rows.");
            }
        }

        $this->assertSame(0, DB::table('workflow_transitions')->count());
        $this->assertSame(0, DB::table('signatures')->count());
        $this->assertSame(0, DB::table('audits')->where('auditable_type', (new PreDpcProposal)->getMorphClass())->count());
        Storage::disk('local')->assertMissing('private/signatures/pre-dpc.pdf');
        Storage::disk('local')->assertExists('private/post-orders/go.pdf');
    }

    public function test_the_dry_run_reports_and_changes_nothing(): void
    {
        $this->artisan('dpc:purge-dpc-data')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(1, DpcProposal::query()->count());
        $this->assertSame(1, DB::table('recruitment_rules')->count());
    }

    public function test_apply_clears_every_dpc_record_and_keeps_everything_else(): void
    {
        $kept = $this->keptCounts();

        $this->artisan('dpc:purge-dpc-data', ['--apply' => true, '--confirm' => $this->database])
            ->expectsOutputToContain('Done:')
            ->assertSuccessful();

        $this->assertDpcDataGone();
        $this->assertSame($kept, $this->keptCounts());
        $this->assertSame(1, DB::table('security_events')->where('event', 'dpc_data.purged')->count());
    }

    public function test_a_wrong_confirmation_or_a_refused_environment_changes_nothing(): void
    {
        $this->artisan('dpc:purge-dpc-data', ['--apply' => true, '--confirm' => 'some_other_db'])
            ->expectsOutputToContain('did not match')
            ->assertFailed();

        config(['dpc.dpc_data_purge.allowed_environments' => ['uat']]);
        $this->artisan('dpc:purge-dpc-data', ['--apply' => true, '--confirm' => $this->database])
            ->expectsOutputToContain('Refused')
            ->assertFailed();

        // Production is refused even when configured.
        config(['dpc.dpc_data_purge.allowed_environments' => ['production']]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->artisan('dpc:purge-dpc-data', ['--apply' => true, '--confirm' => $this->database])->assertFailed();

        $this->assertSame(1, DpcProposal::query()->count());
    }

    public function test_the_administrator_clears_it_from_the_browser(): void
    {
        $kept = $this->keptCounts();

        $this->actingAs($this->admin)->get(route('admin.dpc-data-purge.index'))
            ->assertOk()
            ->assertSee('dpc_proposals')
            ->assertSee('id="dpc-data-purge-form"', false);

        $this->actingAs($this->admin)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame(1, DpcProposal::query()->count());

        $this->actingAs($this->admin)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => $this->database])
            ->assertRedirect(route('admin.dpc-data-purge.index'))
            ->assertSessionHas('status');

        $this->assertDpcDataGone();
        $this->assertSame($kept, $this->keptCounts());
        $this->assertSame($this->admin->id, (int) DB::table('security_events')->where('event', 'dpc_data.purged')->value('actor_id'));
    }

    public function test_only_the_administrator_and_only_where_allowed(): void
    {
        $this->actingAs($this->ao)->get(route('admin.dpc-data-purge.index'))->assertForbidden();
        $this->actingAs($this->ao)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => $this->database])->assertForbidden();

        config(['dpc.dpc_data_purge.allowed_environments' => ['uat']]);

        $this->actingAs($this->admin)->get(route('admin.dpc-data-purge.index'))
            ->assertOk()
            ->assertSee('id="dpc-data-purge-not-allowed"', false)
            ->assertDontSee('id="dpc-data-purge-form"', false);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.dpc-data-purge.index'));
        $this->actingAs($this->admin)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => $this->database])->assertForbidden();

        $this->assertSame(1, DpcProposal::query()->count());
    }

    /**
     * "Sirf admin rakhna hai active mode pe baaki sab delete hona hai" (2026-11-03):
     * with the users option every account but the Administrator goes, a chairman's
     * included, the masters stay, and the Administrator is left active.
     */
    public function test_with_users_only_the_administrator_remains_and_is_active(): void
    {
        $chairman = User::factory()->create();
        $chairman->assignRole(Role::Chairman->value);
        $this->admin->forceFill(['is_active' => false])->save();
        // Registered by its Department Admin, who goes with the users.
        User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $this->department->forceFill(['status' => DepartmentStatus::Registered->value, 'registered_at' => now()])->save();
        $posts = DB::table('department_posts')->count();

        $this->artisan('dpc:purge-dpc-data', ['--with-users' => true])
            ->expectsOutputToContain('Accounts to delete: 4')
            ->assertSuccessful();
        $this->assertSame(5, User::query()->count(), 'the dry run deletes nobody');

        $this->artisan('dpc:purge-dpc-data', ['--apply' => true, '--with-users' => true, '--confirm' => $this->database])
            ->expectsOutputToContain('4 account(s) deleted')
            ->assertSuccessful();

        $this->assertDpcDataGone();
        $this->assertSame([$this->admin->id], User::query()->pluck('id')->all());
        $this->assertTrue((bool) $this->admin->fresh()->is_active);
        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin->value));
        $this->assertSame(0, DB::table('user_department_assignments')->count());
        $this->assertSame($posts, DB::table('department_posts')->count());
        $this->assertSame(1, DB::table('departments')->count());
        // No Department Admin is left, so no department is Registered.
        $this->assertSame(DepartmentStatus::Unregistered, $this->department->fresh()->status);
        $this->assertNull($this->department->fresh()->registered_at);
    }

    public function test_the_administrator_deletes_the_users_from_the_browser_too(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dpc-data-purge.index'))
            ->assertOk()
            ->assertSee('id="dpc-data-purge-users"', false)
            ->assertSee(__('admin.dpc_data_purge.with_users', ['count' => 2]));

        // Unticked, every account stays.
        $this->actingAs($this->admin)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => $this->database]);
        $this->assertSame(3, User::query()->count());

        $this->actingAs($this->admin)->delete(route('admin.dpc-data-purge.destroy'), ['confirmation' => $this->database, 'with_users' => '1'])
            ->assertRedirect(route('admin.dpc-data-purge.index'));
        $this->assertSame([$this->admin->id], User::query()->pluck('id')->all());
        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }
}
