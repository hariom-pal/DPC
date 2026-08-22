<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `pre_dpc_proposals.uuid` — the last aggregate still identified publicly by
 * its sequential id (AD-8, closed in the final phase).
 *
 * **Why this was missed.** Every other aggregate in the application binds its
 * routes on a UUID: proceedings, allotments, schedules, circulations, review
 * links, calculation runs, documents, timeline rows and workflow transitions all
 * declare `getRouteKeyName(): 'uuid'`. Two did not — `dpc_proposals`, which had
 * the column but kept `id` as its route key, and `pre_dpc_proposals`, which
 * never had the column at all. Both were reachable at `/pre-dpc/4` and
 * `/dpc-proposals/4`.
 *
 * **The consequence was not only disclosure.** With `id` as the route key, the
 * binding resolves by coercing the URL segment to an integer, which MySQL does
 * silently: a request for `/dpc-proposals/01a0282e-b21f-…` returned **proposal
 * 1** with a 200 rather than a 404. A guessed or mistyped identifier produced
 * somebody else's record instead of an error.
 *
 * Additive only, and behaviour-preserving for the database: `id` remains the
 * primary key, `parent_version_id` and every other foreign key still reference
 * it, and the version lineage is untouched. The column is nullable while it is
 * backfilled and constrained afterwards, because a partially-filled column
 * cannot carry a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Row by row: MySQL and SQLite share no UUID function (TD-3), and one
        // department has a handful of Pre-DPC versions at most.
        foreach (DB::table('pre_dpc_proposals')->whereNull('uuid')->orderBy('id')->pluck('id') as $id) {
            DB::table('pre_dpc_proposals')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('pre_dpc_proposals', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
