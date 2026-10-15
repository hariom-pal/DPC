<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **An allotment now carries a deadline, and expiring returns the DPC**
 * (change register of 2026-08-26, item 9 as clarified).
 *
 * The register states three facts an allotment must record:
 *
 *  1. **when the DPC was allotted** — `allotted_at`, which this table has always
 *     held;
 *  2. **the date by which it must be concluded** — `complete_by`, new here, set by
 *     the DPC Chairman at the moment of allotting;
 *  3. **the sitting date the allotted member chooses**, which must fall on or
 *     before that deadline — `dpc_schedules.dpc_date`, which already exists.
 *
 * And one rule that follows from them: *if the member does not conclude the DPC by
 * the completion date, it goes back to the Chairman, who allots it again.*
 *
 * ### Who allots
 *
 * `allotted_by` is unchanged as a column and changed entirely in meaning. It used
 * to be the CGPSC Chairman, who allotted every DPC. The register moves that
 * authority: *"DPC will be allocated by DPC chairman not by CGPSC chairman"*. The
 * CGPSC Chairman designates a **DPC Chairman** for each DPC — or takes the role
 * themselves — and that person allots it. The designation lives on
 * `dpc_committee_members.is_dpc_chairman` (migration `400001`); this table records
 * the act.
 *
 * **One member may hold several DPCs at once**, which the register says in as many
 * words, so nothing here constrains an allottee to a single live allotment. That
 * is why the deadline is on the *allotment* and not on the member.
 *
 * ### Why the return is a new row and not a status flip
 *
 * `dpc_allotments` has been append-only since M6 Phase 1, and a missed deadline is
 * the case that vindicates it. Re-allotting after an expiry has to leave both
 * facts standing: *this member held it until this date and did not conclude it*,
 * and *that member holds it now*. Overwriting `allotted_to` answers the second and
 * destroys the first — and the first is the one an officer will be asked about.
 * So expiry sets `returned_at` and `return_reason` on the row that lapsed, and the
 * re-allotment is a new row chained through the existing `superseded_by_id`.
 *
 * ### Nullable, and honestly so
 *
 * `complete_by` is nullable because allotments made before this register have no
 * deadline and never did. Backfilling one would invent a date the Chairman never
 * set and could make a historical allotment retrospectively overdue — a fact the
 * portal would then report to somebody. The service requires it on new allotments;
 * the column permits its absence on old ones. Those are different questions and
 * only one of them is the database's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_allotments', function (Blueprint $table): void {
            /*
             | Set by the DPC Chairman when allotting. The allotted member schedules
             | the sitting on or before this date and may reschedule within it; the
             | deadline itself is the Chairman's and the member cannot move it.
             */
            $table->date('complete_by')->nullable()->after('default_dpc_date');

            // Set when the deadline passed with the DPC unconcluded and it went
            // back to the Chairman. Never set on a concluded allotment.
            $table->timestamp('returned_at')->nullable()->after('accepted_at');
            $table->text('return_reason')->nullable()->after('returned_at');
        });

        /*
         | "Which live allotments are overdue" is the query the Chairman's screen
         | and the sweep both make, and it is the only query in this feature that
         | runs over the whole table rather than one proposal.
         */
        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->index(['status', 'complete_by'], 'dpc_allotments_status_deadline_index');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->dropIndex('dpc_allotments_status_deadline_index');
        });

        // A returned allotment is a state the old schema cannot express: without
        // `returned_at` it reads as still live with somebody, which is the one
        // thing it is not. Superseding it is what the old schema said about an
        // allotment that had been moved on from.
        DB::table('dpc_allotments')
            ->whereNotNull('returned_at')
            ->update(['status' => 'superseded']);

        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->dropColumn(['complete_by', 'returned_at', 'return_reason']);
        });
    }
};
