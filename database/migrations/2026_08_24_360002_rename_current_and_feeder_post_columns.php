<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Current Post" and "Feeder Post" become **Promotion To Post** and **Promotion
 * From Post** on every officer row.
 *
 * Business ruling, 2026-08-24 (change item 6), with the direction of the pairing
 * confirmed by the register on the same date: the **feeder** post is the source
 * of the promotion, so it is the *from* post; the post the officer is recorded
 * against on the chart is the post the exercise fills, so it is the *to* post.
 * That is the direction a recruitment rule already reads in, which is the reason
 * to prefer it over the order the two terms happen to be listed in.
 *
 * **Getting this backwards would have been invisible and permanent.** These four
 * columns appear on the Currently Working chart (S16), the Final Seniority List
 * (S17) and the Evaluation Chart (S18); the last of those is what the scoring
 * engine reads and what the signed promotion list renders. Swapping them would
 * have produced documents that are internally consistent, pass every test, and
 * state the wrong post for every officer in them.
 *
 * `dpc_working_officers` spells its half of the pair `date_of_posting_on_post` —
 * the S16 template's "DATE OF POSTING ON POST" — where the other two charts say
 * `date_of_posting_on_current_post`. Both mean the post the officer holds, so
 * both land on the same new name and the two charts stop differing for no
 * reason.
 *
 * Renames only. No column is added, dropped or retyped, so every stored value
 * and every foreign key is exactly what it was.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, string>>
     */
    private const RENAMES = [
        'dpc_seniority_entries' => [
            'feeder_post' => 'promotion_from_post',
            'current_post' => 'promotion_to_post',
            'date_of_posting_on_feeder_post' => 'date_of_posting_on_promotion_from_post',
            'date_of_posting_on_current_post' => 'date_of_posting_on_promotion_to_post',
        ],
        'dpc_working_officers' => [
            'feeder_post' => 'promotion_from_post',
            'current_post' => 'promotion_to_post',
            'date_of_posting_on_feeder_post' => 'date_of_posting_on_promotion_from_post',
            'date_of_posting_on_post' => 'date_of_posting_on_promotion_to_post',
        ],
        'dpc_evaluation_rows' => [
            'feeder_post' => 'promotion_from_post',
            'current_post' => 'promotion_to_post',
            'date_of_posting_on_feeder_post' => 'date_of_posting_on_promotion_from_post',
            'date_of_posting_on_current_post' => 'date_of_posting_on_promotion_to_post',
        ],
    ];

    public function up(): void
    {
        $this->apply(self::RENAMES);
    }

    public function down(): void
    {
        $this->apply(array_map(
            static fn (array $renames): array => array_flip($renames),
            self::RENAMES,
        ));
    }

    /**
     * @param  array<string, array<string, string>>  $tables
     */
    private function apply(array $tables): void
    {
        foreach ($tables as $table => $renames) {
            Schema::table($table, function (Blueprint $blueprint) use ($renames): void {
                foreach ($renames as $from => $to) {
                    $blueprint->renameColumn($from, $to);
                }
            });
        }
    }
};
