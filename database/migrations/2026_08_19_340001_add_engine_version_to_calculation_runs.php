<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `calculation_engine_version` on the calculation run itself — **TD-21, closed**.
 *
 * Directive 1 (M7) asked for the engine version *"for every calculation run"*. It
 * was recorded on every calculate/recalculated timeline event, on the proceeding,
 * on every Review calculation and on every document — **everywhere except the run
 * row it describes**, because adding it would have meant touching frozen M5 or
 * writing to M5's table past its own immutability guard.
 *
 * Closed under the final-phase Freeze Exception (2026-08-19).
 *
 * **Why it matters where the others do not reach.** A calculation run is the
 * permanent, immutable evidence of a promotion decision, addressed by UUID and
 * explicable years later. Every other place the version is recorded is a
 * *consumer* of the run — a document, a timeline entry, a proceeding — and each
 * can be absent while the run survives. A run without its engine version can be
 * explained only by joining out to something that may not exist.
 *
 * **Nullable, and backfilled from configuration rather than left blank.** Existing
 * runs were produced by the engine version configured now: `dpc.scoring.engine_version`
 * has not changed since the engine was frozen, which is what makes the backfill a
 * statement of fact rather than a guess. It is applied only to rows that have none,
 * so re-running this migration cannot overwrite a real value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_calculation_runs', function (Blueprint $table) {
            $table->string('calculation_engine_version', 50)->nullable()->after('rule_version');
        });

        $version = (string) config('dpc.scoring.engine_version', '');

        if ($version !== '') {
            DB::table('dpc_calculation_runs')
                ->whereNull('calculation_engine_version')
                ->update(['calculation_engine_version' => $version]);
        }
    }

    public function down(): void
    {
        Schema::table('dpc_calculation_runs', function (Blueprint $table) {
            $table->dropColumn('calculation_engine_version');
        });
    }
};
