<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Class Master Level (2026-11-03) is read only by what comes next: a Pre-DPC's
 * post-pair check, the promotion-rule form, a new calculation. What is already
 * recorded holds the class code, not the Level, so changing Levels changes no
 * historical DPC record. And the migration that brought Level touches nothing but
 * the Class Master: no account, no password.
 */
class ClassLevelHistoryTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private const HISTORY = ['dpc_proposals', 'pre_dpc_proposals', 'pre_dpc_promotion_sources', 'department_posts', 'recruitment_rules', 'workflow_transitions'];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function history(): array
    {
        $rows = [];

        foreach (self::HISTORY as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    public function test_changing_levels_changes_no_historical_dpc_record(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'registered']);
        $ao = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create();
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        $post = fn (string $name, string $class): DepartmentPost => DepartmentPost::query()->create([
            'department_id' => $department->id, 'name' => $name, 'class_level' => $class, 'effective_from' => '2026-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $proposal = $this->raiseDpc($ao, $post('Under Secretary', 'II'), $post('Deputy Secretary', 'I'));
        $before = $this->history();

        // The Administrator reorders the hierarchy and adds a class.
        foreach (['I' => 10, 'II' => 20, 'III' => 30] as $code => $level) {
            $class = ClassMaster::query()->where('code', $code)->sole();
            $this->actingAs($admin)->put(route('admin.service-classes.update', $class), [
                'code' => $code, 'name_en' => $class->name_en, 'level' => $level, 'status' => 'active',
            ])->assertSessionHasNoErrors();
        }
        $this->actingAs($admin)->post(route('admin.service-classes.store'), ['code' => 'IV', 'name_en' => 'Class-IV', 'level' => 5, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, $this->history(), 'no historical record changed');
        $this->assertSame('I', DpcProposal::query()->findOrFail($proposal->id)->class_level?->value);
        $this->actingAs($ao)->get(route('dpc-proposals.show', $proposal))->assertOk();
    }

    public function test_the_level_migration_touches_no_account_and_restores_the_order(): void
    {
        $users = User::factory()->count(3)->create();
        $before = DB::table('users')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $migration = require database_path('migrations/2026_11_03_790005_replace_class_order_with_level.php');

        $migration->down();
        $this->assertSame([10, 20, 30], DB::table('service_classes')->orderBy('code')->pluck('sort_order')->map(fn ($v): int => (int) $v)->all());
        $migration->up();

        $this->assertSame(['I' => 1, 'II' => 2, 'III' => 3], DB::table('service_classes')->pluck('level', 'code')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        $this->assertCount(3, $users);
    }
}
