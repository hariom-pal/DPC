<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction sequence and calculation tracking — M7 Phase 3.
 *
 * **`dpc_proceeding_overrides.sequence`** (architect Directive 1) numbers every
 * correction within its proceeding, from 1. It is not decoration: it is what
 * makes **stale-calculation protection deterministic**. The obvious test —
 * "does any correction post-date the run?" — compares timestamps, and
 * `applied_at` has second granularity, so a correction and a recalculation in
 * the same second would be indistinguishable. A monotonic sequence has no such
 * ambiguity, and a calculation that recorded sequence 4 provably does not
 * reflect correction 5.
 *
 * **`dpc_proceedings.latest_calculation_run_id`** and
 * **`calculated_through_sequence`** record which run is current and how much
 * of the correction history it saw. `final_calculation_run_id` (Phase 1) is
 * separate and is pinned only at approval — the run the Commission actually
 * attested to, which must not move afterwards even if someone calculates
 * again.
 *
 * Additive only. Existing rows are backfilled in id order, which is their
 * insertion order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proceeding_overrides', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0)->after('dpc_proceeding_id');

            // The department's own entry for this cell, snapshotted when the
            // correction is recorded. `previous_value` is the value that was in
            // force *before this correction*, which after a chain is what the
            // previous correction made it — not what the department sent. The
            // old-beside-new display needs both, and Directive 2 requires it to
            // be reproducible from the timeline without reading M4.
            $table->string('submitted_value')->nullable()->after('previous_value');
        });

        // Backfill per proceeding, in insertion order. Row-by-row because
        // MySQL and SQLite share no portable windowing here and the volumes
        // are trivial; if this ever runs against a large table, write
        // per-driver batched SQL instead (TD-3).
        $counters = [];
        foreach (DB::table('dpc_proceeding_overrides')->orderBy('id')->get(['id', 'dpc_proceeding_id']) as $row) {
            $next = ($counters[$row->dpc_proceeding_id] ?? 0) + 1;
            $counters[$row->dpc_proceeding_id] = $next;

            DB::table('dpc_proceeding_overrides')->where('id', $row->id)->update(['sequence' => $next]);
        }

        Schema::table('dpc_proceeding_overrides', function (Blueprint $table) {
            $table->unique(['dpc_proceeding_id', 'sequence'], 'dpc_override_proceeding_sequence_unique');

            // Directive 2 — repository filtering by officer, field and year.
            // No UI needs these yet; the index exists so the first caller that
            // does is not the one that discovers the table scan.
            $table->index(['dpc_proceeding_id', 'field'], 'dpc_override_proceeding_field_index');
            $table->index(['dpc_proceeding_id', 'year'], 'dpc_override_proceeding_year_index');
        });

        Schema::table('dpc_proceedings', function (Blueprint $table) {
            $table->foreignId('latest_calculation_run_id')->nullable()->after('promotion_as_on_year')
                ->constrained('dpc_calculation_runs')->nullOnDelete();

            $table->unsignedInteger('calculated_through_sequence')->nullable()
                ->after('latest_calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proceedings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_calculation_run_id');
            $table->dropColumn('calculated_through_sequence');
        });

        Schema::table('dpc_proceeding_overrides', function (Blueprint $table) {
            $table->dropUnique('dpc_override_proceeding_sequence_unique');
            $table->dropIndex('dpc_override_proceeding_field_index');
            $table->dropIndex('dpc_override_proceeding_year_index');
            $table->dropColumn(['sequence', 'submitted_value']);
        });
    }
};
