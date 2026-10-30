<?php

declare(strict_types=1);

use App\Domain\Master\Data\QualificationCatalogue;
use App\Enums\MasterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "NA" in the Minimum Qualification master (MoM of 2026-10-29), so a deployment
 * that runs migrations only gets it too. Inserted only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $entry = collect(QualificationCatalogue::LIST)->firstWhere('code', QualificationCatalogue::NOT_APPLICABLE);

        if ($entry === null || DB::table('qualifications')->where('code', $entry['code'])->exists()) {
            return;
        }

        DB::table('qualifications')->insert([
            'code' => $entry['code'],
            'name_en' => $entry['name_en'],
            'name_hi' => $entry['name_hi'],
            'description' => $entry['description'],
            'status' => MasterStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('qualifications')->where('code', QualificationCatalogue::NOT_APPLICABLE)->delete();
    }
};
