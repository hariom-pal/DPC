<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Creates no account (business decision, 2026-10-26).
 *
 * The Portal Super Admin used to be seeded with a configured — and by default
 * shared — password. It is now created by `php artisan dpc:bootstrap-super-admin`,
 * which generates a random password and shows it once to the operator. This seeder
 * is kept so an old `--class=AdminUserSeeder` call says where the account comes
 * from instead of failing.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('No account seeded. Create the Portal Super Admin with: php artisan dpc:bootstrap-super-admin');
    }
}
