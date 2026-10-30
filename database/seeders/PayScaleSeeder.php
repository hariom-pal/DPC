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
            ['label' => 'Level-10 (56100-177500)', 'code' => 'L10', 'minimum_pay' => 56100],
            ['label' => 'Level-11 (67700-208700)', 'code' => 'L11', 'minimum_pay' => 67700],
            ['label' => 'Level-12 (78800-209200)', 'code' => 'L12', 'minimum_pay' => 78800],
            ['label' => 'Level-13 (123100-215900)', 'code' => 'L13', 'minimum_pay' => 123100],
            ['label' => 'Level-13A (131100-216600)', 'code' => 'L13A', 'minimum_pay' => 131100],
            ['label' => 'Level-14 (144200-218200)', 'code' => 'L14', 'minimum_pay' => 144200],
        ];

        foreach ($scales as $scale) {
            PayScale::query()->updateOrCreate(
                ['label' => $scale['label']],
                ['code' => $scale['code'], 'minimum_pay' => $scale['minimum_pay'], 'status' => MasterStatus::Active->value],
            );
        }
    }
}
