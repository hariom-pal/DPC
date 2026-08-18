<?php

declare(strict_types=1);

use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\RecruitmentRuleFeeder;
use App\Enums\MasterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point a recruitment rule's minimum qualification at the Qualification master,
 * and stop the same feeder cadre being attached to one rule twice.
 *
 * **Additive only.** `min_qualification` is deliberately *kept*:
 *
 *  - it is the record of what the Government Order actually said, and this
 *    project does not rewrite evidence;
 *  - a value that no master row matches must stay readable rather than being
 *    silently dropped — an unmapped row is a data-quality finding, and dropping
 *    the column would drop the finding with it;
 *  - the migration discipline for this repo is additive-only.
 *
 * So `qualification_id` becomes the field the application reads and writes, and
 * `min_qualification` becomes the legacy record behind it.
 * {@see RecruitmentRuleFeeder::qualificationLabel()}
 * prefers the master and falls back to the text, so no screen that shows a
 * qualification can regress.
 *
 * **This migration seeds the master itself** before backfilling. It has to: on a
 * fresh database, seeders run after migrations, so a backfill that waited for
 * `QualificationSeeder` would have nothing to match against and every row would
 * come out null. Seeding here makes `php artisan migrate` sufficient on its own,
 * which is the point of a backward-compatible migration. `QualificationSeeder`
 * upserts the same catalogue by code afterwards, so running both is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_rule_feeders', function (Blueprint $table) {
            $table->foreignId('qualification_id')
                ->nullable()
                ->after('min_experience_years')
                ->constrained('qualifications')
                ->nullOnDelete();
        });

        $this->seedCatalogue();
        $this->backfillQualifications();
        $this->addUniqueFeederIndex();
    }

    public function down(): void
    {
        Schema::table('recruitment_rule_feeders', function (Blueprint $table) {
            $table->dropUnique('rr_feeders_rule_cadre_unique');
            $table->dropConstrainedForeignId('qualification_id');
        });
    }

    /**
     * Insert any catalogue entry that is not already present, matched on code so
     * an existing master is never overwritten or duplicated.
     */
    private function seedCatalogue(): void
    {
        $existing = DB::table('qualifications')->pluck('id', 'code');
        $now = now();
        $rows = [];

        foreach (QualificationCatalogue::LIST as $entry) {
            if ($existing->has($entry['code'])) {
                continue;
            }

            $rows[] = $entry + [
                'status' => MasterStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('qualifications')->insert($rows);
        }
    }

    /**
     * Map every distinct free-text qualification onto a master row.
     *
     * Matches a normalised form (case-folded, punctuation and whitespace
     * stripped) against both the code and the English name, because the text was
     * entered by hand. Rows whose text matches nothing keep a null
     * `qualification_id` and their original text, and are listed by
     * `php artisan qualifications:unmapped`.
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

        $texts = DB::table('recruitment_rule_feeders')
            ->whereNotNull('min_qualification')
            ->where('min_qualification', '<>', '')
            ->distinct()
            ->pluck('min_qualification');

        foreach ($texts as $text) {
            $id = $master[QualificationCatalogue::matchKey((string) $text)] ?? null;

            if ($id !== null) {
                DB::table('recruitment_rule_feeders')
                    ->where('min_qualification', $text)
                    ->update(['qualification_id' => $id]);
            }
        }
    }

    /**
     * One feeder cadre may appear on a rule once.
     *
     * Existing data was verified clean before this was written. If a future
     * dataset is not, this fails loudly with the offending pairs named, rather
     * than half-applying the constraint or enforcing it only from the next write
     * onwards.
     */
    private function addUniqueFeederIndex(): void
    {
        $duplicates = DB::table('recruitment_rule_feeders')
            ->select('recruitment_rule_id', 'feeder_cadre_id')
            ->groupBy('recruitment_rule_id', 'feeder_cadre_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the unique feeder index: the same feeder cadre appears more than once on '
                .$duplicates->count().' recruitment rule(s) — '
                .$duplicates->map(static fn ($row): string => "rule #{$row->recruitment_rule_id}/cadre #{$row->feeder_cadre_id}")->implode(', ')
                .'. Resolve the duplicates and re-run.'
            );
        }

        Schema::table('recruitment_rule_feeders', function (Blueprint $table) {
            $table->unique(['recruitment_rule_id', 'feeder_cadre_id'], 'rr_feeders_rule_cadre_unique');
        });
    }
};
