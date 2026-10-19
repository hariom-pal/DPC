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
 * The baseline Department Post Mapping: one promotion pair in School Education —
 * Block Education Officer (Class II) → Additional Director, Education (Class I) —
 * enough for one DPC from a fresh database (2026-10-17). Further posts are added by
 * the Administrator on the Department Post screen.
 *
 * Each mapping cites the Government Order that establishes it.
 */
class DepartmentPostSeeder extends Seeder
{
    private const GOVERNMENT_ORDER = 'F-2-14/2025/20-Three';

    private const EFFECTIVE_FROM = '2026-01-01';

    public function run(): void
    {
        $department = Department::query()->where('code', 'SE')->first();

        if ($department === null) {
            return;
        }

        $scales = PayScale::query()->pluck('id', 'code');

        $posts = [
            // Promotion Duration is the qualifying service in the feeder post: a
            // Pre-DPC copies it from the Promotion From Post.
            ['name' => 'Block Education Officer', 'class' => ServiceClass::II, 'scale' => 'L12', 'sanctioned' => 4, 'duration' => 3],
            ['name' => 'Additional Director, Education', 'class' => ServiceClass::I, 'scale' => 'L13A', 'sanctioned' => 10, 'duration' => null],
        ];

        foreach ($posts as $post) {
            $mapping = DepartmentPost::query()->updateOrCreate(
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
