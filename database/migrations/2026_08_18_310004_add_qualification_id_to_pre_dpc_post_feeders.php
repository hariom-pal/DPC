<?php

declare(strict_types=1);

use App\Domain\Master\Data\QualificationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point the Pre-DPC's minimum qualification at the Qualification master.
 *
 * The Recruitment Rule master gained `qualification_id` on 2026-08-17, and the
 * Pre-DPC screen was deliberately left alone at the time: the department still
 * typed the qualification, and resolution to the master happened at the moment
 * of projection. **That is now the wrong place for it.** Business asked on
 * 2026-08-18 for the Pre-DPC screen itself to select from the master, which
 * means the reference has to be captured where it is chosen rather than guessed
 * afterwards from what somebody typed.
 *
 * **Additive only, and `min_qualification` is deliberately kept** — the same
 * three reasons as the recruitment-rule migration it mirrors:
 *
 *  - it records what the department actually wrote, and this project does not
 *    rewrite evidence;
 *  - text that matches no master row must stay readable, because dropping the
 *    column would drop the data-quality finding along with the data;
 *  - the migration discipline for this repo is additive-only.
 *
 * After this, `qualification_id` is what the application reads and writes and
 * `min_qualification` is the legacy record behind it. `PreDpcPostFeeder::qualificationLabel()`
 * prefers the master and falls back to the text, so no screen can regress.
 *
 * **No catalogue seeding here**, unlike `…_310002_…`. That migration had to seed
 * because it introduced the `qualifications` table's first consumer on a fresh
 * database. The table and its catalogue already exist by the time this runs, so
 * seeding again would be noise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_dpc_post_feeders', function (Blueprint $table) {
            $table->foreignId('qualification_id')
                ->nullable()
                ->after('min_experience_years')
                ->constrained('qualifications')
                ->nullOnDelete();
        });

        $this->backfillQualifications();
    }

    public function down(): void
    {
        Schema::table('pre_dpc_post_feeders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualification_id');
        });
    }

    /**
     * Map every distinct free-text qualification onto a master row.
     *
     * Matches a normalised form — case-folded, punctuation and whitespace
     * stripped — against both the code and the English name, because the text
     * was entered by hand: `Post-Graduate`, `post graduate` and `PG` are one
     * qualification. Rows matching nothing keep a null `qualification_id` **and
     * their original text**; an unmapped row is a finding to be surfaced, never
     * a guess to be made.
     *
     * Deliberately identical in method to the recruitment-rule backfill, so the
     * same text resolves the same way on both sides of the projection.
     */
    private function backfillQualifications(): void
    {
        $master = [];

        foreach (DB::table('qualifications')->select('id', 'code', 'name_en')->get() as $qualification) {
            foreach ([$qualification->code, $qualification->name_en] as $candidate) {
                $key = QualificationCatalogue::matchKey((string) $candidate);

                if ($key !== '') {
                    $master[$key] ??= (int) $qualification->id;
                }
            }
        }

        if ($master === []) {
            return;
        }

        $texts = DB::table('pre_dpc_post_feeders')
            ->whereNotNull('min_qualification')
            ->where('min_qualification', '<>', '')
            ->distinct()
            ->pluck('min_qualification');

        foreach ($texts as $text) {
            $id = $master[QualificationCatalogue::matchKey((string) $text)] ?? null;

            if ($id !== null) {
                DB::table('pre_dpc_post_feeders')
                    ->where('min_qualification', $text)
                    ->update(['qualification_id' => $id]);
            }
        }
    }
};
