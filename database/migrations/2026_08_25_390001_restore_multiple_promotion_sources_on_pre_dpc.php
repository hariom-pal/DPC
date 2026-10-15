<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the several **Promotion From Posts** a promotion may draw on.
 *
 * **This is a regression fix, not a redesign.** Before the change register of
 * 2026-08-24 a Pre-DPC named posts and each post named its feeders — `pre_dpc_posts`
 * and `pre_dpc_post_feeders`. Reading change item 9's uniqueness key
 * ("Department + From Post + To Post + DPC Year") as *one pair per order* collapsed
 * both child tables into a single pair of columns, and with them went the ability to
 * say what Schedule-IV says on nearly every rung: **Deputy Director is filled from
 * Assistant Director, Senior Lecturer and Research Officer.**
 *
 * The shape restored here is the original one, minus the level the register did
 * remove: a Pre-DPC is one **order**, for one **Promotion To Post**, listing every
 * post officers may be promoted from.
 *
 * **What moves, and why each column moves where it does.**
 *
 * | Column | Where it belongs now | Because |
 * |---|---|---|
 * | `promotion_from_post_id` | the source row | there are several |
 * | `min_experience_years`, `experience_unit` | the source row | Schedule-IV sets the qualifying service **per feeder** — five years as an Assistant Director is not five years as a Research Officer |
 * | `qualification_id` | the source row | likewise |
 * | `promotion_duration_years` | the source row | it is the qualifying service on *that* post, read from that post's own mapping |
 * | `promotion_recruitment_pct` | stays on the order | the promotion quota belongs to the post being filled |
 * | `pay_scale_id`, `class_level` | stay on the order | they describe the **To** post, and `MemberLevelRule` reads `class_level` as the class of the promotion post (item 16). Moving it would silently change who may sit on a committee |
 *
 * **The exercise key narrows, and that is the point.** It was
 * `(department, from post, to post, year)`; it is now `(department, to post, year)`.
 * One order sanctions promotion into one post for one year — which is what it always
 * meant, and is why the old design needed no From Post in the key either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_dpc_promotion_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pre_dpc_proposal_id')->constrained('pre_dpc_proposals')->cascadeOnDelete();

            // Restricted, not cascaded: a Department Post named by a signed order
            // cannot be deleted out from under it.
            $table->foreignId('promotion_from_post_id')->constrained('department_posts')->restrictOnDelete();

            $table->decimal('promotion_percentage', 5, 2)->default(0);
            $table->unsignedSmallInteger('min_experience_years')->default(0);
            $table->string('experience_unit', 20)->default('years');
            $table->foreignId('qualification_id')->nullable()->constrained('qualifications')->nullOnDelete();

            // Read from the feeder post's own mapping when the order is saved, so a
            // signed order states the qualifying service it sanctioned rather than
            // whatever the master says years later.
            $table->unsignedSmallInteger('promotion_duration_years')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One order may not name the same feeder twice.
            $table->unique(['pre_dpc_proposal_id', 'promotion_from_post_id'], 'pre_dpc_source_unique');
        });

        $this->backfill();

        /*
         | **Both** unique indexes name the feeder, and both have to be replaced.
         |
         | The replacements are created before the incumbents are dropped: the
         | outgoing indexes cover `promotion_from_post_id`'s foreign key, and MySQL
         | refuses to drop the only index covering a key's column (errno 1553).
         |
         | And they must be dropped *explicitly* rather than left to the column drop.
         | MySQL quietly removes a dropped column from a composite index and carries
         | on; **SQLite refuses the drop outright** while any index still names the
         | column. Leaving it to the engine would have produced a migration that ran
         | in development and failed in the test suite — which is precisely what it
         | did until this was corrected.
         */
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->unique(
                ['department_id', 'promotion_to_post_id', 'dpc_year', 'active_flag'],
                'pre_dpc_active_exercise_unique_v2',
            );
            $table->unique(
                ['department_id', 'promotion_to_post_id', 'dpc_year', 'version'],
                'pre_dpc_exercise_version_unique_v2',
            );
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_active_exercise_unique');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_exercise_version_unique');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropForeign(['promotion_from_post_id']);
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropForeign(['qualification_id']);
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn([
                'promotion_from_post_id',
                'min_experience_years',
                'experience_unit',
                'qualification_id',
                'promotion_duration_years',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->foreignId('promotion_from_post_id')->nullable()->after('dpc_year')
                ->constrained('department_posts')->restrictOnDelete();
            $table->unsignedSmallInteger('min_experience_years')->default(0)->after('promotion_recruitment_pct');
            $table->string('experience_unit', 20)->default('years')->after('min_experience_years');
            $table->foreignId('qualification_id')->nullable()->after('experience_unit')
                ->constrained('qualifications')->nullOnDelete();
            $table->unsignedSmallInteger('promotion_duration_years')->nullable()->after('class_level');
        });

        // The first source of each order becomes the single feeder again. Any
        // further sources cannot be represented and are lost — which is exactly the
        // regression this migration exists to undo, and is why `down()` is a way
        // back rather than a round trip.
        foreach (DB::table('pre_dpc_promotion_sources')->orderBy('sort_order')->orderBy('id')->get() as $source) {
            DB::table('pre_dpc_proposals')
                ->where('id', $source->pre_dpc_proposal_id)
                ->whereNull('promotion_from_post_id')
                ->update([
                    'promotion_from_post_id' => $source->promotion_from_post_id,
                    'min_experience_years' => $source->min_experience_years,
                    'experience_unit' => $source->experience_unit,
                    'qualification_id' => $source->qualification_id,
                    'promotion_duration_years' => $source->promotion_duration_years,
                ]);
        }

        // Orders whose several sources collapsed to one may now collide on the old
        // key, so the narrow index is restored only after the data fits it.
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->unique(
                ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'active_flag'],
                'pre_dpc_active_exercise_unique',
            );
            $table->unique(
                ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'version'],
                'pre_dpc_exercise_version_unique',
            );
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_active_exercise_unique_v2');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_exercise_version_unique_v2');
        });

        Schema::dropIfExists('pre_dpc_promotion_sources');
    }

    /**
     * Every existing order becomes an order with exactly one source.
     *
     * Nothing is lost and nothing is invented: the pair the order already names
     * becomes its first — and for now only — Promotion From Post.
     */
    private function backfill(): void
    {
        $orders = DB::table('pre_dpc_proposals')
            ->whereNotNull('promotion_from_post_id')
            ->get([
                'id', 'promotion_from_post_id', 'promotion_recruitment_pct',
                'min_experience_years', 'experience_unit', 'qualification_id', 'promotion_duration_years',
            ]);

        foreach ($orders as $order) {
            DB::table('pre_dpc_promotion_sources')->insert([
                'pre_dpc_proposal_id' => $order->id,
                'promotion_from_post_id' => $order->promotion_from_post_id,

                // A single feeder takes the whole promotion quota, which is what the
                // order already said.
                'promotion_percentage' => $order->promotion_recruitment_pct,

                'min_experience_years' => $order->min_experience_years,
                'experience_unit' => $order->experience_unit,
                'qualification_id' => $order->qualification_id,
                'promotion_duration_years' => $order->promotion_duration_years,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
