<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One race-safe serial counter, now serving two series.
 *
 * Change item 10 asks for a unique Pre-DPC Number (`PDPC-2026-HED-0001`) beside
 * the existing DPC Number. Both are "per department, per year, next serial, taken
 * under a row lock so two concurrent approvals cannot be issued the same one" —
 * and the second copy of that is where the two quietly stop behaving alike. The
 * table gains a `series` discriminator instead.
 *
 * `dpc` is the default so every existing row keeps its meaning and
 * `DpcIdGeneratorService` keeps issuing from exactly the counter it was already
 * issuing from. The unique key widens from (department, year) to (department,
 * year, series), which is the same constraint per series that it was before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->string('series', 20)->default('dpc')->after('department_id');
        });

        // Existing counters are DPC counters. Stated explicitly rather than left
        // to the column default, because the default applies to inserts and these
        // rows already exist.
        DB::table('dpc_id_sequences')->update(['series' => 'dpc']);

        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->unique(['department_id', 'series', 'year'], 'id_sequences_department_series_year_unique');
        });

        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->dropUnique('dpc_id_sequences_department_id_year_unique');
        });
    }

    public function down(): void
    {
        // Only the DPC series can survive the narrower key. Any Pre-DPC counter
        // is dropped, which is safe in one direction only — re-running `up()`
        // would restart Pre-DPC serials from 1 and could reissue a number. The
        // reverse of this migration is therefore for a rollback that is followed
        // by a redeploy, not by continued use.
        DB::table('dpc_id_sequences')->where('series', '!=', 'dpc')->delete();

        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->unique(['department_id', 'year'], 'dpc_id_sequences_department_id_year_unique');
        });

        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->dropUnique('id_sequences_department_series_year_unique');
        });

        Schema::table('dpc_id_sequences', function (Blueprint $table): void {
            $table->dropColumn('series');
        });
    }
};
