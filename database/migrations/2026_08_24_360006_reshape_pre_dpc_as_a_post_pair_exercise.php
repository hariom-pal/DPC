<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A Pre-DPC becomes **one promotion exercise**: one department, one Promotion
 * From Post, one Promotion To Post, one DPC Year.
 *
 * Business ruling, 2026-08-24 (change items 8, 9, 10 and 11).
 *
 * **Why the shape had to change and not merely gain a column.** The register
 * says three things that only fit together one way:
 *
 *   - every Pre-DPC carries a DPC Year (item 8);
 *   - only one *active approved* Pre-DPC may exist per department + From Post +
 *     To Post + DPC Year (item 9);
 *   - a DPC inherits its From Post and To Post *from the Pre-DPC* (item 11).
 *
 * A Pre-DPC that held a list of posts, each with a list of feeders, cannot
 * satisfy any of them: there is no single pair for the uniqueness key to be about
 * and nothing for the DPC to inherit. So the pair moves onto the header and the
 * two child tables go.
 *
 * **A post filled from several feeders loses nothing.** It becomes several
 * Pre-DPCs — one per feeder — each separately approved and separately active, and
 * the Recruitment Rule for that post is composed from the active set. That is
 * also what makes the uniqueness key meaningful: two exercises promoting into
 * Deputy Director, one from Assistant Director and one from Research Officer, are
 * genuinely two exercises and were always going to be run as two DPCs.
 *
 * **`is_current` is replaced, not kept.** It meant "the department's live approved
 * Pre-DPC" — the same idea as item 9's Active Flag at the wrong granularity, and
 * two columns asserting one fact is how they come to disagree. `active_flag` is
 * nullable for the same reason as on `user_department_assignments`: NULLs do not
 * collide in a unique index, so `unique(department, from, to, year, active_flag)`
 * says *exactly* "at most one active per exercise, any number of superseded ones"
 * — enforced by the database rather than by remembering to check.
 *
 * **Nothing is deleted, per item 9.** Approving a new Pre-DPC for an exercise
 * clears the incumbent's flag; the incumbent keeps its row, its number, its
 * signature and its approval date.
 *
 * **The backfill splits rather than truncates.** Every existing (post, feeder)
 * pair becomes a Pre-DPC. The first pair rewrites the original row — so its id,
 * signature, approval and workflow history are all preserved — and each further
 * pair is inserted as a sibling at the same version. Siblings carry no workflow
 * history, because none happened to them; that is recorded in the final report
 * rather than papered over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            // Item 10. Nullable until the backfill has issued one for every row.
            $table->string('pre_dpc_number', 40)->nullable()->after('id');

            // Item 8.
            $table->unsignedSmallInteger('dpc_year')->nullable()->after('department_id');

            // Item 11's inheritance, in the order the screens read it.
            $table->foreignId('promotion_from_post_id')->nullable()->after('dpc_year')
                ->constrained('department_posts')->restrictOnDelete();
            $table->foreignId('promotion_to_post_id')->nullable()->after('promotion_from_post_id')
                ->constrained('department_posts')->restrictOnDelete();

            /*
             | Derived from the pair by `PostAttributeResolver` and stored, not
             | re-read (item 5).
             |
             | Stored because a Pre-DPC is a Government Order once approved: it
             | sanctioned *these* terms, and a later edit to the Department Post
             | master must not silently restate what an approved order said. The
             | resolver fills them; nobody types them.
             */
            $table->foreignId('pay_scale_id')->nullable()->after('promotion_to_post_id')
                ->constrained('pay_scales')->nullOnDelete();
            $table->string('class_level', 5)->nullable()->after('pay_scale_id');
            $table->unsignedSmallInteger('promotion_duration_years')->nullable()->after('class_level');

            // The recruitment-rule terms this exercise sanctions — previously on
            // `pre_dpc_posts` and `pre_dpc_post_feeders`, now on the one row that
            // is the exercise.
            $table->decimal('direct_recruitment_pct', 5, 2)->default(0)->after('promotion_duration_years');
            $table->decimal('promotion_recruitment_pct', 5, 2)->default(0)->after('direct_recruitment_pct');
            $table->unsignedSmallInteger('min_experience_years')->default(0)->after('promotion_recruitment_pct');
            $table->string('experience_unit', 10)->default('years')->after('min_experience_years');
            $table->foreignId('qualification_id')->nullable()->after('experience_unit')
                ->constrained('qualifications')->nullOnDelete();

            // Item 9.
            $table->unsignedTinyInteger('active_flag')->nullable()->after('status');
        });

        $this->backfill();

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->unique('pre_dpc_number', 'pre_dpc_number_unique');

            // Item 9, enforced by the schema.
            $table->unique(
                ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'active_flag'],
                'pre_dpc_active_exercise_unique',
            );

            // Versions are now per exercise rather than per department: two
            // exercises in the same department are unrelated and must not share a
            // version counter.
            $table->unique(
                ['department_id', 'promotion_from_post_id', 'promotion_to_post_id', 'dpc_year', 'version'],
                'pre_dpc_exercise_version_unique',
            );
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_proposals_department_id_version_unique');
        });

        // The index goes in its own statement before the column: SQLite refuses
        // to drop a column an index still names.
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropIndex('pre_dpc_proposals_is_current_index');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('is_current');
        });

        // The two child tables the header replaced. Dropped in dependency order.
        Schema::dropIfExists('pre_dpc_post_feeders');
        Schema::dropIfExists('pre_dpc_posts');
    }

    public function down(): void
    {
        Schema::create('pre_dpc_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pre_dpc_proposal_id')->constrained('pre_dpc_proposals')->cascadeOnDelete();
            $table->string('post_name');
            $table->string('class_level', 5);
            $table->foreignId('pay_scale_id')->nullable()->constrained('pay_scales')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->decimal('direct_recruitment_pct', 5, 2)->default(0);
            $table->decimal('promotion_recruitment_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pre_dpc_post_feeders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pre_dpc_post_id')->constrained('pre_dpc_posts')->cascadeOnDelete();
            $table->string('feeder_name');
            $table->foreignId('promotion_from_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->decimal('promotion_percentage', 5, 2)->default(0);
            $table->unsignedSmallInteger('min_experience_years')->default(0);
            $table->string('experience_unit', 10)->default('years');
            $table->string('min_qualification')->nullable();
            $table->foreignId('qualification_id')->nullable()->constrained('qualifications')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['pre_dpc_post_id', 'promotion_from_post_id'], 'pre_dpc_feeder_unique');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->boolean('is_current')->default(false)->index()->after('parent_version_id');
        });

        // Restore the department view of "current" from the exercise-scoped flag.
        // A department with several active exercises necessarily loses all but one
        // — the column cannot hold them, which is why item 9 replaced it.
        DB::table('pre_dpc_proposals')->whereNotNull('active_flag')->update(['is_current' => true]);

        // The old key is restored *before* the new ones are dropped, and in that
        // order for the same reason `up()` created them in the order it did: on
        // MySQL these composite uniques are the only cover for the `department_id`
        // foreign key, and an uncovered foreign key cannot exist even for the
        // duration of one statement (error 1553).
        //
        // Renumbering comes first because the split created siblings sharing a
        // version, which is exactly what the old key forbade.
        $this->collapseDuplicateVersions();

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->unique(['department_id', 'version'], 'pre_dpc_proposals_department_id_version_unique');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropUnique('pre_dpc_exercise_version_unique');
            $table->dropUnique('pre_dpc_active_exercise_unique');
            $table->dropUnique('pre_dpc_number_unique');
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('qualification_id');
            $table->dropConstrainedForeignId('pay_scale_id');
            $table->dropConstrainedForeignId('promotion_to_post_id');
            $table->dropConstrainedForeignId('promotion_from_post_id');
            $table->dropColumn([
                'pre_dpc_number', 'dpc_year', 'class_level', 'promotion_duration_years',
                'direct_recruitment_pct', 'promotion_recruitment_pct',
                'min_experience_years', 'experience_unit', 'active_flag',
            ]);
        });
    }

    /**
     * Turn every existing (post, feeder) pair into a Pre-DPC exercise.
     *
     * The first pair of each proposal rewrites that proposal in place, so its id
     * — and therefore its signature, its approval, its audit trail and every
     * workflow transition recorded against it — is preserved exactly. Each
     * further pair is inserted as a sibling.
     */
    private function backfill(): void
    {
        $serials = [];

        foreach (DB::table('pre_dpc_proposals')->orderBy('id')->get() as $proposal) {
            $pairs = $this->pairsFor((int) $proposal->id);
            $year = $this->yearFor($proposal);

            if ($pairs === []) {
                // A draft with no posts yet. It gets a number and a year so the
                // NOT NULL-ness of the number holds, and the officer chooses the
                // pair when they next open it.
                DB::table('pre_dpc_proposals')->where('id', $proposal->id)->update([
                    'pre_dpc_number' => $this->nextNumber($serials, (int) $proposal->department_id, $year),
                    'dpc_year' => $year,
                    'active_flag' => $proposal->is_current ? 1 : null,
                ]);

                continue;
            }

            foreach ($pairs as $index => $pair) {
                $attributes = [
                    'pre_dpc_number' => $this->nextNumber($serials, (int) $proposal->department_id, $year),
                    'dpc_year' => $year,
                    'promotion_from_post_id' => $pair['from_post_id'],
                    'promotion_to_post_id' => $pair['to_post_id'],
                    'pay_scale_id' => $pair['pay_scale_id'],
                    'class_level' => $pair['class_level'],
                    'direct_recruitment_pct' => $pair['direct_recruitment_pct'],
                    'promotion_recruitment_pct' => $pair['promotion_recruitment_pct'],
                    'min_experience_years' => $pair['min_experience_years'],
                    'experience_unit' => $pair['experience_unit'],
                    'qualification_id' => $pair['qualification_id'],

                    // Only the first pair inherits "current". Marking every
                    // sibling active would be inventing approvals that never
                    // happened for the second and third feeder.
                    'active_flag' => ($index === 0 && $proposal->is_current) ? 1 : null,
                ];

                if ($index === 0) {
                    DB::table('pre_dpc_proposals')->where('id', $proposal->id)->update($attributes);

                    continue;
                }

                DB::table('pre_dpc_proposals')->insert($attributes + [
                    'department_id' => $proposal->department_id,
                    'version' => $proposal->version,
                    'parent_version_id' => null,
                    'status' => $proposal->status,
                    'government_order_number' => $proposal->government_order_number,
                    'effective_from' => $proposal->effective_from,
                    'prepared_by' => $proposal->prepared_by,
                    'submitted_at' => $proposal->submitted_at,
                    'approved_by' => $proposal->approved_by,
                    'approved_at' => $proposal->approved_at,

                    // Deliberately not the original's signature: a signature is
                    // over a document, and this sibling is not that document.
                    'signature_id' => null,

                    'uuid' => (string) Str::uuid7(),
                    'created_at' => $proposal->created_at,
                    'updated_at' => now(),
                ]);
            }
        }

        $this->seedNumberSequences($serials);
    }

    /**
     * Hand the backfill's counters to `dpc_id_sequences`, so the first number the
     * running application issues is the one after the last number issued here.
     *
     * Without this the generator would start every department's `pre_dpc` series
     * at 1 and hand out a number that a migrated proposal already carries — which
     * the unique index would refuse, at the moment an Administrative Head pressed
     * Approve.
     *
     * @param  array<string, int>  $serials  "<departmentId>:<year>" => highest serial issued
     */
    private function seedNumberSequences(array $serials): void
    {
        foreach ($serials as $key => $lastSerial) {
            [$departmentId, $year] = array_map('intval', explode(':', $key));

            DB::table('dpc_id_sequences')->updateOrInsert(
                ['department_id' => $departmentId, 'series' => 'pre_dpc', 'year' => $year],
                ['last_serial' => $lastSerial, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * The (post, feeder) pairs recorded against one proposal, flattened.
     *
     * @return list<array<string, mixed>>
     */
    private function pairsFor(int $proposalId): array
    {
        $pairs = [];

        $posts = DB::table('pre_dpc_posts')
            ->where('pre_dpc_proposal_id', $proposalId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($posts as $post) {
            // A post chosen from the master is that row; one carrying only a name
            // has nothing to point at, and the officer re-selects it. Guessing by
            // name here would be the TD-25 mistake in a migration.
            if ($post->post_id === null) {
                continue;
            }

            $feeders = DB::table('pre_dpc_post_feeders')
                ->where('pre_dpc_post_id', $post->id)
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            foreach ($feeders as $feeder) {
                if ($feeder->promotion_from_post_id === null) {
                    continue;
                }

                $pairs[] = [
                    'from_post_id' => $feeder->promotion_from_post_id,
                    'to_post_id' => $post->post_id,
                    'pay_scale_id' => $post->pay_scale_id,
                    'class_level' => $post->class_level,
                    'direct_recruitment_pct' => $post->direct_recruitment_pct,
                    'promotion_recruitment_pct' => $feeder->promotion_percentage,
                    'min_experience_years' => $feeder->min_experience_years,
                    'experience_unit' => $feeder->experience_unit ?? 'years',
                    'qualification_id' => $feeder->qualification_id,
                ];
            }
        }

        // Two feeders of the same post would collide on the new unique key only
        // if they were the same post, which the old schema already forbade.
        return $pairs;
    }

    /**
     * Which DPC Year an existing proposal belongs to.
     *
     * The Government Order's effective date if there is one, else the approval,
     * else the row's creation — in decreasing order of how much the date has to do
     * with the exercise. Never a guess beyond that.
     */
    private function yearFor(object $proposal): int
    {
        foreach (['effective_from', 'approved_at', 'created_at'] as $column) {
            if (($proposal->{$column} ?? null) !== null) {
                return (int) Carbon::parse((string) $proposal->{$column})->format('Y');
            }
        }

        return (int) now()->format('Y');
    }

    /**
     * `PDPC-<year>-<DEPT>-<serial>` (item 10), counted per department per year.
     *
     * The counter is local to this migration rather than shared with
     * `PreDpcNumberGenerator`: the generator locks a sequence row per call, which
     * is right for concurrent approvals and pointless inside a single-threaded
     * backfill. The sequence table is seeded from these totals at the end so the
     * two never issue the same number.
     *
     * @param  array<string, int>  $serials
     */
    private function nextNumber(array &$serials, int $departmentId, int $year): string
    {
        $code = (string) DB::table('departments')->where('id', $departmentId)->value('code');
        $key = $departmentId.':'.$year;
        $serials[$key] = ($serials[$key] ?? 0) + 1;

        return sprintf('PDPC-%d-%s-%04d', $year, $code, $serials[$key]);
    }

    /**
     * Give siblings distinct versions so the old per-department unique key can be
     * reinstated.
     */
    private function collapseDuplicateVersions(): void
    {
        $next = [];

        foreach (DB::table('pre_dpc_proposals')->orderBy('department_id')->orderBy('id')->get() as $proposal) {
            $department = (int) $proposal->department_id;
            $next[$department] = ($next[$department] ?? 0) + 1;

            DB::table('pre_dpc_proposals')->where('id', $proposal->id)->update(['version' => $next[$department]]);
        }
    }
};
