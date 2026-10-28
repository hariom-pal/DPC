<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every dropdown is searchable (2026-10-26): the enhancer is loaded by every
 * shell, and it needs no marker on the select — the plain server-rendered
 * <select> is what the browser receives and what submits.
 */
class SearchableDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_shell_loads_the_searchable_dropdown(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The public shell.
        $this->get(route('login'))->assertOk()->assertSee('js/searchable-select.js', false);

        // The administration shell, on a form with a dropdown.
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        $this->actingAs($admin)->get(route('admin.departments.create'))
            ->assertOk()
            ->assertSee('<select', false)
            ->assertSee('js/searchable-select.js', false);

        $this->assertFileExists(public_path('js/searchable-select.js'));
    }

    public function test_the_department_shell_loads_it_too(): void
    {
        $this->assertStringContainsString(
            "asset('js/searchable-select.js')",
            (string) file_get_contents(resource_path('views/layouts/app.blade.php')),
        );
    }
}
