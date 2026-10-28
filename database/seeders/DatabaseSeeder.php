<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh installation: required master and reference data only (business
 * decision, 2026-10-26).
 *
 * No account of any kind is seeded — the Portal Super Admin is created by
 * `php artisan dpc:bootstrap-super-admin` — and no post, Pre-DPC, vacancy record,
 * DPC or workflow record. The department master is seeded (`DepartmentMasterSeeder`,
 * 2026-10-26: School Education, unregistered, with no account); further
 * departments and every post are entered by the Portal Super Admin, and a
 * department registers by invitation.
 *
 * Kept out on purpose, and still available to call by class on a demonstration
 * machine: `DepartmentPostSeeder` (the School Education posts),
 * `ReservationRosterSeeder` (an illustrative roster per post),
 * `ReadyDpcSeeder` (a department ready to start a DPC) and
 * `DemoSeeder` (`php artisan demo:seed`).
 *
 * Some master data is created by migrations rather than here: the service
 * classes, record reasons, sealed-cover reasons, CR grades and the PH reservation
 * policy.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DistrictSeeder::class,
            DesignationSeeder::class,
            PayScaleSeeder::class,
            LookupMasterSeeder::class,
            QualificationSeeder::class,
            DepartmentMasterSeeder::class,
            CommitteeMasterSeeder::class,
            CgpscChairmanSeeder::class,
        ]);
    }
}
