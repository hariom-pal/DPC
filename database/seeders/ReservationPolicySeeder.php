<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * Default (editable) reservation percentages. These are provisional defaults —
 * they are configuration data, not hardcoded rules, and the admin confirms /
 * amends them. Effective-dated so historic DPCs use the applicable policy.
 */
class ReservationPolicySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'SC' => 12.00,
            'ST' => 32.00,
            'OBC' => 14.00,
            'EWS' => 10.00,
        ];

        foreach ($defaults as $code => $percentage) {
            $category = ReservationCategory::query()->where('code', $code)->first();

            if ($category === null) {
                continue;
            }

            ReservationPolicy::query()->updateOrCreate(
                ['reservation_category_id' => $category->id, 'effective_from' => '2020-01-01'],
                ['percentage' => $percentage, 'effective_to' => null, 'status' => MasterStatus::Active->value],
            );
        }
    }
}
