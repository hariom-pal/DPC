<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds the department master list shown in the registration dropdown (PPT S6).
 * Codes form the prefix of the DPC ID (e.g. "HE" -> HE201711111).
 */
class DepartmentMasterSeeder extends Seeder
{
    public function run(): void
    {
        // One department (2026-10-19): further departments are added by the
        // Administrator.
        $departments = [
            ['code' => 'SE', 'name' => 'School Education Department'],
        ];

        foreach ($departments as $department) {
            // Created unregistered; a re-run leaves a registered department as it is.
            Department::query()->firstOrCreate(
                ['code' => $department['code']],
                ['name' => $department['name'], 'status' => DepartmentStatus::Unregistered->value],
            );
        }
    }
}
