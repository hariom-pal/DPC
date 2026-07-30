<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the CGPSC Admin account (approved addition — Q2) that provisions
 * Chairman/Member accounts and manages master data in later milestones.
 * Credentials are configuration-driven; change the password immediately in
 * any non-local environment.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => (string) config('dpc.admin.email')],
            [
                'name' => (string) config('dpc.admin.name'),
                'mobile' => (string) config('dpc.admin.mobile'),
                'password' => Hash::make((string) config('dpc.admin.password')),
                'is_active' => true,
                'email_verified_at' => now(),
                'mobile_verified_at' => now(),
            ],
        );

        $admin->syncRoles([Role::Admin->value]);
    }
}
