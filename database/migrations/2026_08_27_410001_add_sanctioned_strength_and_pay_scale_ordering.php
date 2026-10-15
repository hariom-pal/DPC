<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two master figures the Vacancy and Merit engines need and the masters did
 * not carry (change register of 2026-08-27, items 1 and 11).
 *
 * ### Sanctioned Strength belongs to the mapping, not to the proposal
 *
 * > *Sanctioned Strength shall come from Department Post Mapping. The AO should
 * > not manually enter vacancy figures.*
 *
 * It was `dpc_proposals.sanctioned_post_count`, typed on the vacancy screen once
 * per proposal. Two things were wrong with that, and the second is why the
 * register asks for the change:
 *
 *  - the same post's sanctioned strength was re-entered on every DPC, so two
 *    proposals for one post could disagree about how many posts exist; and
 *  - it was **the officer's number**, which is exactly what a sanctioned strength
 *    is not. It is what a Government Order says, and the mapping is where this
 *    portal keeps what Government Orders say.
 *
 * `dpc_proposals.sanctioned_post_count` is **kept**, and that is deliberate. A
 * concluded DPC ran against the strength recorded at the time; reading it live
 * from the master would let a mapping edited next year change what a signed
 * proposal says it was calculated from. So the column becomes a *snapshot*
 * written from the master when the vacancy is calculated, rather than an input.
 *
 * ### Pay Scale needed to be comparable
 *
 * > *Promotion shall always be from a lower post to a higher post. Validate using
 * > Class, Pay Scale, Grade Pay.*
 *
 * `pay_scales` carried a label and a code and nothing orderable, so "higher pay
 * scale to lower pay scale" was not a question the data could answer. `level` is
 * the ordinal and `grade_pay` is the figure the register names separately.
 *
 * **Both are nullable, and the comparison skips a missing value rather than
 * failing on it.** A scale registered before this migration has neither, and a
 * master-data gap is not a promotion rule — refusing every promotion out of an
 * unnumbered scale would make an incomplete master look like an ineligible
 * officer. `PromotionHierarchyService` says which checks it was able to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_posts', function (Blueprint $table): void {
            /*
             | The number of posts the Government has sanctioned for this mapping.
             |
             | Nullable because every existing mapping has none, and the vacancy
             | engine reports "not recorded" rather than calculating from a guess.
             | Effective-dated for free: a new Government Order supersedes the
             | mapping, so a change of strength is a new row and the old DPC still
             | reads the strength it ran under.
             */
            $table->unsignedInteger('sanctioned_strength')->nullable()->after('promotion_duration_years');
        });

        Schema::table('pay_scales', function (Blueprint $table): void {
            // The ordinal. Higher means senior; the Administrator maintains it.
            $table->unsignedSmallInteger('level')->nullable()->after('code');

            // The figure the register names in its own right (item 11).
            $table->unsignedInteger('grade_pay')->nullable()->after('level');
        });

        $this->backfillSanctionedStrength();
    }

    public function down(): void
    {
        Schema::table('pay_scales', function (Blueprint $table): void {
            $table->dropColumn(['level', 'grade_pay']);
        });

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropColumn('sanctioned_strength');
        });
    }

    /**
     * Seed each mapping's strength from the proposals that already ran against it.
     *
     * **Only where every proposal agrees.** A post whose DPCs recorded 12 and then
     * 14 has genuinely had two sanctioned strengths, and this migration cannot know
     * which is current — so it writes nothing and the Administrator records it. A
     * migration that picked the latest would look authoritative and be a guess.
     *
     * Where every proposal for a post recorded the same number, that number is not
     * a guess: it is what the department has been calculating against all along,
     * and carrying it over means the master is populated on the day this ships
     * rather than blanking every vacancy screen until somebody visits each mapping.
     */
    private function backfillSanctionedStrength(): void
    {
        if (! Schema::hasColumn('dpc_proposals', 'sanctioned_post_count')) {
            return;
        }

        $agreed = DB::table('dpc_proposals')
            ->select('promotion_to_post_id')
            ->whereNotNull('promotion_to_post_id')
            ->whereNotNull('sanctioned_post_count')
            ->groupBy('promotion_to_post_id')
            ->havingRaw('COUNT(DISTINCT sanctioned_post_count) = 1')
            ->selectRaw('MIN(sanctioned_post_count) AS strength')
            ->get();

        foreach ($agreed as $row) {
            DB::table('department_posts')
                ->where('id', $row->promotion_to_post_id)
                ->whereNull('sanctioned_strength')
                ->update(['sanctioned_strength' => (int) $row->strength]);
        }
    }
};
