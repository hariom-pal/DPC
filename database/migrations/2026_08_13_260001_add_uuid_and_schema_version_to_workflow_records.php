<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Workflow-record provenance (architect directives, M6 Phase 2).
 *
 * **Directive: every workflow transition gets its own UUID.** A transition is
 * a legal record of who changed what and when; referring to one by sequential
 * id in a document or an export discloses how many exist and invites guessing
 * neighbours, which is the same reasoning already applied to calculation runs
 * and allotments.
 *
 * **Directive: every timeline event stores a schema version, starting at 1.**
 * The timeline is the legal workflow history, and a future PDF/CSV/audit
 * export must be able to read a record written years earlier. Without a
 * version stamp, an exporter would have to guess which shape a row's
 * `properties` are in; with one, it can branch explicitly. Version 1 is the
 * shape documented in `docs/archive/2026-08-19-pre-final/WORKFLOWS.md`.
 *
 * Timeline rows also gain a UUID: an export that can be regenerated must
 * identify its rows stably and without leaking sequential ids.
 *
 * Additive only. Existing rows are backfilled so no record is left without
 * provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->unsignedSmallInteger('schema_version')->default(1)->after('properties');
        });

        $this->backfillUuids('workflow_transitions');
        $this->backfillUuids('activity_timelines');

        // Unique only after backfill — a partially-filled column cannot carry
        // the constraint.
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });

        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });

        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'schema_version']);
        });
    }

    /**
     * Backfilled row by row rather than with a single SQL expression: MySQL's
     * UUID() and SQLite have no common function, and these tables are small
     * enough at this stage of the project that portability is worth more than
     * speed.
     */
    private function backfillUuids(string $table): void
    {
        DB::table($table)->whereNull('uuid')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }
};
