<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\District;
use App\Enums\MasterStatus;
use App\Integrations\Contracts\DirectoryProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds Chhattisgarh districts from the configured DirectoryProvider (LG
 * Directory). Idempotent on name_en.
 */
class DistrictSeeder extends Seeder
{
    public function run(DirectoryProvider $directory): void
    {
        foreach ($directory->districts() as $district) {
            District::query()->updateOrCreate(
                ['name_en' => $district['name_en']],
                [
                    'name_hi' => $district['name_hi'],
                    'state_lgd_code' => $district['state_lgd_code'],
                    'lgd_code' => $district['lgd_code'],
                    'status' => MasterStatus::Active->value,
                ],
            );
        }
    }
}
