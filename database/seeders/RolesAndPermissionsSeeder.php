<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            SpatieRole::findOrCreate($role->value, 'web');
        }
    }
}
