<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Master\Models\Designation;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The CGPSC Chairman: the account (role `chairman`) and the serving record.
 *
 * Every DPC committee seats the serving Chairman, and `DpcCommitteeService` refuses
 * to compose one without a record, so a freshly seeded portal needs both. Credentials
 * come from `dpc.chairman`, like the Administrator's from `dpc.admin`.
 *
 * Idempotent: an already serving Chairman is left in office.
 */
class CgpscChairmanSeeder extends Seeder
{
    private const DESIGNATION = 'Chairman, Chhattisgarh Public Service Commission';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => (string) config('dpc.chairman.email')],
            [
                'name' => (string) config('dpc.chairman.name'),
                'mobile' => (string) config('dpc.chairman.mobile'),
                'designation_id' => Designation::query()->where('name', 'Chairman, CGPSC')->value('id'),
                'password' => Hash::make((string) config('dpc.chairman.password')),
                'is_active' => true,
                'email_verified_at' => now(),
                'mobile_verified_at' => now(),
            ],
        );

        $user->syncRoles([Role::Chairman->value]);

        if (CgpscChairman::query()->serving()->exists()) {
            return;
        }

        app(CgpscChairmanService::class)->appoint([
            'user_id' => $user->id,
            'name' => $user->name,
            'designation' => self::DESIGNATION,
            'email' => $user->email,
            'mobile' => $user->mobile,
        ]);
    }
}
