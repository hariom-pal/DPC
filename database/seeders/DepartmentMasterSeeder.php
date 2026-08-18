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
        $departments = [
            ['code' => 'GAD', 'name' => 'General Administration Department'],
            ['code' => 'SE', 'name' => 'School Education Department'],
            ['code' => 'HE', 'name' => 'Higher Education Department'],
            ['code' => 'FIN', 'name' => 'Finance Department'],
            ['code' => 'HLT', 'name' => 'Health Department'],
            ['code' => 'AGR', 'name' => 'Agriculture Department'],
            ['code' => 'ME', 'name' => 'Medical Education Department'],
            ['code' => 'LAB', 'name' => 'Labour Department'],
            ['code' => 'PWD', 'name' => 'Public Works Department'],
        ];

        foreach ($departments as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department['code']],
                ['name' => $department['name'], 'status' => DepartmentStatus::Unregistered->value],
            );
        }
    }
}
