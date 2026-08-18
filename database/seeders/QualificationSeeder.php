<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\Qualification;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * The Qualification master's starting list.
 *
 * Upserts on `code`, so this is safe to re-run and safe to run after the
 * migration that introduced `recruitment_rule_feeders.qualification_id` — that
 * migration seeds the same catalogue itself, because its backfill needs
 * something to match against before seeders have run.
 *
 * @see QualificationCatalogue for why the list lives in one shared place
 */
class QualificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (QualificationCatalogue::LIST as $entry) {
            Qualification::query()->updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name_en' => $entry['name_en'],
                    'name_hi' => $entry['name_hi'],
                    'description' => $entry['description'],
                    'status' => MasterStatus::Active->value,
                ],
            );
        }
    }
}
