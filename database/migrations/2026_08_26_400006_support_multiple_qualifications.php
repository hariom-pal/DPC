<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **A promotion source may prescribe several acceptable qualifications**
 * (change register of 2026-08-26, item 11).
 *
 * > *For Recruitment Rules / Pre-DPC, Qualification must support multiple
 * > selection. Eligible Qualifications: BE, BTech, MTech, MCA, MSc. Store all
 * > selected qualifications properly.*
 *
 * The register's example is the ordinary case rather than an edge one: a
 * Schedule-IV entry for a technical post accepts *any* of a list of degrees, and
 * `qualification_id` — a single nullable reference — could record only one of
 * them. A department entering "BE or BTech or MCA" had to pick one and lose the
 * rest, or fall back to the free-text `min_qualification`, which nothing can
 * match against.
 *
 * **The relationship is a set, so it is a pivot table.** Two of them, because the
 * two sides are recorded independently: the Recruitment Rule master says what the
 * rule prescribes, and the Pre-DPC order says what *this* exercise was sanctioned
 * on. They are usually the same and are not required to be — an order may be
 * issued under a rule that has since been amended — and collapsing them would
 * make one rewrite the other.
 *
 * ### What happens to the old columns
 *
 * `qualification_id` is **dropped** on both tables once its value is carried into
 * the pivot. A single reference beside a set is two answers to one question, and
 * the next person to read it will not know which is authoritative. One row in the
 * pivot expresses exactly what one `qualification_id` expressed.
 *
 * `min_qualification` — the free text — is **kept**, on both tables, for the three
 * reasons the migrations that introduced `qualification_id` already gave and which
 * have not stopped being true:
 *
 *  - it records what the Government Order actually said, and this project does not
 *    rewrite evidence;
 *  - text matching no master row must stay readable, because dropping the column
 *    would drop the data-quality finding along with the data;
 *  - `qualifications:unmapped` reports on it.
 *
 * ### Constraint names are explicit, and that is not a style choice
 *
 * `pre_dpc_source_qualifications_pre_dpc_promotion_source_id_foreign` is 64
 * characters — exactly MySQL's limit, with nothing to spare if a column is ever
 * renamed. Its recruitment-rule counterpart is over. This repository has already
 * lost a day to a derived 65-character constraint that passed the whole SQLite
 * suite and could not be created on MySQL at all, which is why
 * `SchemaIdentifierLengthTest` exists. Naming them here keeps them short and keeps
 * them stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rr_source_qualifications', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('recruitment_rule_source_id');
            $table->unsignedBigInteger('qualification_id');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('recruitment_rule_source_id', 'rr_source_qual_source_fk')
                ->references('id')->on('recruitment_rule_sources')
                ->cascadeOnDelete();

            /*
             | `restrictOnDelete` rather than `cascade`: withdrawing a qualification
             | from the master must not silently loosen a recruitment rule. The
             | master soft-deletes, so this is reached only by a hard delete, and
             | refusing it is the right answer.
             */
            $table->foreign('qualification_id', 'rr_source_qual_qualification_fk')
                ->references('id')->on('qualifications')
                ->restrictOnDelete();

            $table->unique(
                ['recruitment_rule_source_id', 'qualification_id'],
                'rr_source_qual_unique',
            );
        });

        Schema::create('pre_dpc_source_qualifications', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('pre_dpc_promotion_source_id');
            $table->unsignedBigInteger('qualification_id');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('pre_dpc_promotion_source_id', 'pre_dpc_source_qual_source_fk')
                ->references('id')->on('pre_dpc_promotion_sources')
                ->cascadeOnDelete();

            $table->foreign('qualification_id', 'pre_dpc_source_qual_qualification_fk')
                ->references('id')->on('qualifications')
                ->restrictOnDelete();

            $table->unique(
                ['pre_dpc_promotion_source_id', 'qualification_id'],
                'pre_dpc_source_qual_unique',
            );
        });

        $this->carryForward('recruitment_rule_sources', 'rr_source_qualifications', 'recruitment_rule_source_id');
        $this->carryForward('pre_dpc_promotion_sources', 'pre_dpc_source_qualifications', 'pre_dpc_promotion_source_id');

        Schema::table('recruitment_rule_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('qualification_id');
        });

        Schema::table('pre_dpc_promotion_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('qualification_id');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_rule_sources', function (Blueprint $table): void {
            $table->foreignId('qualification_id')->nullable()->after('min_experience_years')
                ->constrained('qualifications')->nullOnDelete();
        });

        Schema::table('pre_dpc_promotion_sources', function (Blueprint $table): void {
            $table->foreignId('qualification_id')->nullable()->after('experience_unit')
                ->constrained('qualifications')->nullOnDelete();
        });

        $this->carryBack('recruitment_rule_sources', 'rr_source_qualifications', 'recruitment_rule_source_id');
        $this->carryBack('pre_dpc_promotion_sources', 'pre_dpc_source_qualifications', 'pre_dpc_promotion_source_id');

        Schema::dropIfExists('rr_source_qualifications');
        Schema::dropIfExists('pre_dpc_source_qualifications');
    }

    /**
     * Every source that named a qualification gets a pivot row naming the same one.
     *
     * Chunked rather than one statement per row: a department with a full
     * Schedule-IV has a few hundred sources, and this runs inside a deployment.
     */
    private function carryForward(string $sourceTable, string $pivotTable, string $foreignKey): void
    {
        if (! Schema::hasColumn($sourceTable, 'qualification_id')) {
            return;
        }

        $now = now();

        DB::table($sourceTable)
            ->whereNotNull('qualification_id')
            ->select('id', 'qualification_id')
            ->orderBy('id')
            ->chunk(500, function ($sources) use ($pivotTable, $foreignKey, $now): void {
                $rows = $sources->map(fn ($source): array => [
                    $foreignKey => $source->id,
                    'qualification_id' => $source->qualification_id,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table($pivotTable)->insert($rows);
            });
    }

    /**
     * Put back **the first** qualification as the single reference.
     *
     * Lossy, necessarily: the old column holds one and the pivot may hold five.
     * The first by `sort_order` is chosen because that is the order the form
     * presents them in, so it is the one a reader would call primary — but the
     * others are discarded, and saying so here is the point of this note.
     */
    private function carryBack(string $sourceTable, string $pivotTable, string $foreignKey): void
    {
        $first = DB::table($pivotTable)
            ->orderBy($foreignKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy($foreignKey);

        foreach ($first as $sourceId => $group) {
            DB::table($sourceTable)
                ->where('id', $sourceId)
                ->update(['qualification_id' => $group->first()->qualification_id]);
        }
    }
};
