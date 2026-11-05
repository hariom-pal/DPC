<?php

declare(strict_types=1);

namespace Tests\Feature\Allotment;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Department Admin's menu, in the order the business set (2026-11-04): Post
 * Mapping first, then Create New Pre-DPC, User Management, and the three lists.
 */
class DepartmentAdminMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_department_admin_menu_is_in_the_business_order(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'registered']);
        $admin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSeeInOrder([
            'data-menu="post-mapping"',
            'data-menu="create-pre-dpc"',
            'data-menu="users"',
            'data-menu="pre-dpc"',
            'data-menu="running"',
            'data-menu="closed"',
        ], false);

        // The dashboard's tiles in the same order.
        $tiles = (string) $this->get(route('dashboard'))->getContent();
        $tiles = substr($tiles, (int) strpos($tiles, __('ui.dashboard.your_workspace')));
        $at = array_map(fn (string $url): int|false => strpos($tiles, 'href="'.e($url).'"'), [
            route('department.posts.index'),
            route('department.allotments.pre-dpc.create'),
            route('department.users.index'),
            route('department.allotments.index', ['view' => 'pre-dpc']),
            route('department.allotments.index'),
            route('department.allotments.index', ['view' => 'closed']),
        ]);
        $this->assertNotContains(false, $at, 'Every tile is shown.');
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'The tiles follow the menu order.');
    }
}
