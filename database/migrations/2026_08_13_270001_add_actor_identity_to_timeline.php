<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Actor identity on every timeline event (architect directive, M6 Phase 3).
 *
 * The timeline is the legal workflow history, and it must stay readable when
 * the users it refers to no longer do. Two problems the existing
 * `causer_type`/`causer_id` pair cannot solve:
 *
 * 1. **A deleted or renamed user corrupts the record.** `causer_id` resolves
 *    to nothing, or to a different name than the one that acted. A snapshot of
 *    the display name at the moment of the event is the only thing that stays
 *    true.
 * 2. **Replay needs a stable identity.** A future export or integration
 *    regenerating events from the timeline alone cannot use a sequential id —
 *    it discloses user counts and is not stable across environments.
 *
 * `users.uuid` is added for the same reason: an actor referenced publicly must
 * be referenced by UUID (standing ruling). It is additive; the auto-increment
 * `id` remains the primary key and every existing relation is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        $this->backfillUuids('users');

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });

        Schema::table('activity_timelines', function (Blueprint $table) {
            // Nullable: a system-generated event has no human actor, and that
            // is a fact worth recording rather than papering over.
            $table->uuid('actor_uuid')->nullable()->after('causer_id');
            $table->string('actor_name')->nullable()->after('actor_uuid');

            $table->index('actor_uuid');
        });

        // Backfill existing timeline rows from the users they point at. Rows
        // whose causer has since gone stay null — inventing a name would be
        // worse than admitting the record predates the snapshot.
        DB::table('activity_timelines')
            ->whereNull('actor_uuid')
            ->whereNotNull('causer_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $user = DB::table('users')->where('id', $row->causer_id)->first(['uuid', 'name']);

                    if ($user !== null) {
                        DB::table('activity_timelines')->where('id', $row->id)->update([
                            'actor_uuid' => $user->uuid,
                            'actor_name' => $user->name,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->dropIndex(['actor_uuid']);
            $table->dropColumn(['actor_uuid', 'actor_name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }

    private function backfillUuids(string $table): void
    {
        DB::table($table)->whereNull('uuid')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }
};
