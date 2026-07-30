<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

class DesignationSeeder extends Seeder
{
    public function run(): void
    {
        $designations = [
            'Secretary', 'Additional Secretary', 'Joint Secretary', 'Deputy Secretary',
            'Under Secretary', 'Section Officer', 'Assistant Grade-1', 'Assistant Grade-2',
            'Assistant Grade-3', 'Law Officer', 'Librarian',
        ];

        foreach ($designations as $name) {
            Designation::query()->updateOrCreate(
                ['name' => $name],
                ['status' => MasterStatus::Active->value],
            );
        }
    }
}
