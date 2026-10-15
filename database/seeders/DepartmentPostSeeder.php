<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Database\Seeder;

/**
 * Sample posts for the Higher Education Department (PPT S9 posts) so the
 * roster/recruitment-rule screens have data to work with. Real posts are
 * maintained per department by the admin.
 */
class DepartmentPostSeeder extends Seeder
{
    public function run(): void
    {
        $department = Department::query()->where('code', 'HE')->first();

        if ($department === null) {
            return;
        }

        $posts = [
            ['Deputy Secretary', ServiceClass::I],
            ['Under Secretary', ServiceClass::I],
            ['Section Officer', ServiceClass::II],
            ['Assistant Grade-1', ServiceClass::III],
        ];

        foreach ($posts as [$name, $class]) {
            DepartmentPost::query()->updateOrCreate(
                ['department_id' => $department->id, 'name' => $name],
                ['class_level' => $class->value, 'status' => MasterStatus::Active->value],
            );
        }
    }
}
