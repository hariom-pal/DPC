<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance of every Review calculation — M8 Phase 3.
 *
 * Architect Directives 1 and 2: persist the **configuration snapshot UUID** and
 * the **original calculation run UUID** for every Review calculation.
 *
 * Neither can live on `dpc_calculation_runs`: that table belongs to
 * engine-frozen M5, and writing to it from M8 would mean editing frozen code or
 * writing behind its immutability guard (the trap TD-19 and TD-21 record). So
 * M8 keeps its own provenance record, keyed to the run by UUID.
 *
 * **`configuration_snapshot_uuid` is derived from the snapshot's content**, not
 * randomly generated — a UUIDv5 over the canonical JSON. Two calculations that
 * ran under identical rules therefore carry the **same** snapshot UUID, which
 * turns "were these two results decided under the same rules?" into an equality
 * check. A random UUID per run would have recorded only that a snapshot
 * existed, which is a fact nobody needs.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_review_calculations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_review_link_id')->constrained('dpc_review_links')->cascadeOnDelete();
            $table->foreignId('dpc_calculation_run_id')->constrained('dpc_calculation_runs')->cascadeOnDelete();

            // Recorded as UUID strings as well as foreign keys: a provenance
            // record must stay readable without depending on a join surviving.
            $table->uuid('run_uuid');
            $table->uuid('configuration_snapshot_uuid');
            $table->uuid('original_calculation_run_uuid')->nullable();

            $table->string('rule_version', 50)->nullable();
            $table->string('calculation_engine_version', 50)->nullable();

            $table->timestamp('calculated_at');

            $table->timestamps();

            $table->unique('dpc_calculation_run_id', 'dpc_review_calc_run_unique');
            $table->index('configuration_snapshot_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_review_calculations');
    }
};
