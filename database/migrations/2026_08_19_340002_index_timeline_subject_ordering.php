<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The composite index the timeline is actually read by — **TD-4, closed**.
 *
 * Every timeline read is *"everything that happened to this subject, in order"*:
 * filtered on `(subject_type, subject_id)` and ordered by `(occurred_at, uuid)`.
 * The table had an index on the filter and a separate one on `occurred_at`, so the
 * sort was satisfied by neither — the database filtered on one index and then
 * sorted the result set.
 *
 * **The `uuid` tie-break is not decorative.** `occurred_at` has second granularity
 * and a workflow transition writes two rows inside one transaction, so ordering by
 * `occurred_at` alone is non-deterministic — a defect found and fixed in M6 Phase 4.
 * The index has to cover the tie-break or the deterministic order it guarantees is
 * re-established by a sort on every read.
 *
 * Immaterial at current volumes, which is why it was deferred; a promotion record
 * is retained permanently by ruling, so this table only grows, and the read that
 * needs it is the one performed when a promotion is contested.
 *
 * Index-only: no column added, none dropped, no data touched.
 */
return new class extends Migration
{
    private const NAME = 'activity_timelines_subject_ordering_index';

    public function up(): void
    {
        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'occurred_at', 'uuid'], self::NAME);
        });
    }

    public function down(): void
    {
        Schema::table('activity_timelines', function (Blueprint $table) {
            $table->dropIndex(self::NAME);
        });
    }
};
