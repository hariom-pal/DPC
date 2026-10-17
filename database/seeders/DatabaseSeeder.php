<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
            DepartmentPostSeeder::class,
            ReservationRosterSeeder::class,
            CommitteeMasterSeeder::class,
            AdminUserSeeder::class,
            CgpscChairmanSeeder::class,
        ]);
    }
}
