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

            // The Commission's own, and the School Education ladder the baseline
            // data uses (2026-10-17: the seeded data is the development baseline).
            'Chairman, CGPSC', 'Member, CGPSC', 'System Administrator',
            'Chairman, Chhattisgarh Public Service Commission',
            'Director, Public Instruction', 'Additional Director, Education',
            'Joint Director, Education', 'District Education Officer',
            'Principal, Higher Secondary School', 'Block Education Officer',
            'Lecturer', 'Head Master, Middle School', 'Teacher',
            'Secretary, School Education', 'Deputy Secretary, GAD',
        ];

        foreach ($designations as $name) {
            Designation::query()->updateOrCreate(
                ['name' => $name],
                ['status' => MasterStatus::Active->value],
            );
        }
    }
}
