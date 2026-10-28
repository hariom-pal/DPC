<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Master\Models\PayScale;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Database\Seeder;

/**
 * A starting Department Post Mapping for Higher Education (2026-10-26): the
 * college-teaching promotion line Assistant Professor → Professor → Principal, so
 * the Department Admin can open and allot a Pre-DPC for it.
 *
 * **Placeholder figures.** The class and pay level are the nearest entries in the
 * Pay Scale master, and the sanctioned strength, qualifying service and Government
 * Order number are illustrative — not taken from an order. Correct them on the
 * Department Post screen before a real DPC.
 *
 * Not part of `DatabaseSeeder`; run by class. Idempotent on (department, post
 * name); an existing post is left as the Administrator has it.
 */
class HigherEducationPostSeeder extends Seeder
{
    private const GOVERNMENT_ORDER = 'HE/DPM/PLACEHOLDER/2026';

    private const EFFECTIVE_FROM = '2026-01-01';

    public function run(): void
    {
        $department = Department::query()->where('code', 'HE')->first();

        if ($department === null) {
            $this->command->warn('No Higher Education department (code HE); nothing seeded.');

            return;
        }

        $scales = PayScale::query()->pluck('id', 'code');

        // Promotion Duration is the qualifying service in the feeder post: a
        // Pre-DPC copies it from the Promotion From Post.
        $posts = [
            ['name' => 'Assistant Professor', 'class' => ServiceClass::II, 'scale' => 'L10', 'sanctioned' => 100, 'duration' => 10],
            ['name' => 'Professor', 'class' => ServiceClass::I, 'scale' => 'L13A', 'sanctioned' => 30, 'duration' => 5],
            ['name' => 'Principal, Government College', 'class' => ServiceClass::I, 'scale' => 'L14', 'sanctioned' => 20, 'duration' => null],
        ];

        foreach ($posts as $post) {
            $mapping = DepartmentPost::query()->firstOrCreate(
                ['department_id' => $department->id, 'name' => $post['name']],
                [
                    'class_level' => $post['class']->value,
                    'pay_scale_id' => $scales[$post['scale']] ?? null,
                    'sanctioned_strength' => $post['sanctioned'],
                    'promotion_duration_years' => $post['duration'],
                    'effective_from' => self::EFFECTIVE_FROM,
                    'status' => MasterStatus::Active->value,
                ],
            );

            DepartmentPostOrder::query()->firstOrCreate(
                ['department_post_id' => $mapping->id, 'government_order_number' => self::GOVERNMENT_ORDER],
                ['government_order_date' => self::EFFECTIVE_FROM],
            );
        }
    }
}
