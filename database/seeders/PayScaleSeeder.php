<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\PayScale;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

class PayScaleSeeder extends Seeder
{
    public function run(): void
    {
        $scales = [
            ['label' => 'Level-10 (56100-177500)', 'code' => 'L10'],
            ['label' => 'Level-11 (67700-208700)', 'code' => 'L11'],
            ['label' => 'Level-12 (78800-209200)', 'code' => 'L12'],
            ['label' => 'Level-13 (123100-215900)', 'code' => 'L13'],
        ];

        foreach ($scales as $scale) {
            PayScale::query()->updateOrCreate(
                ['label' => $scale['label']],
                ['code' => $scale['code'], 'status' => MasterStatus::Active->value],
            );
        }
    }
}
