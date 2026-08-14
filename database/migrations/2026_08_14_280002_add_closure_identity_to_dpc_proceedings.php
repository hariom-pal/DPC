<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closure identity on a proceeding (architect Directive 1, M7 Phase 2).
 *
 * A proceeding must record **Opened By, Opened At, Closed By, Closed At**.
 * Phase 1 delivered the first pair — `conducted_by` / `conducted_by_name` is
 * the Opened By, and `opened_at` the Opened At. Under AQ-6 the same person
 * necessarily conducts what they opened, so a separate `opened_by` column
 * would be a second name for one fact, and two columns that must agree are two
 * columns that can disagree.
 *
 * The closing pair is genuinely separate and is added here. It is **not**
 * derivable from `approved_at` / `cancelled_at`: those record *when a decision
 * was taken*, whereas closure records *who ended the sitting*. Phase 3 — which
 * implements `approve_result` and `cancel_dpc` — populates them; the columns
 * exist now because the directive is a rule about the record, not about the
 * phase that happens to fill it in.
 *
 * Additive only. Nothing existing is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proceedings', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('conducted_by_name')
                ->constrained('users')->nullOnDelete();

            // Actor snapshot, for the same reason the opening one exists: a
            // reference tells you who that user is now, a snapshot tells you
            // who closed the sitting then.
            $table->string('closed_by_name')->nullable()->after('closed_by');

            $table->timestamp('closed_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proceedings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_by_name', 'closed_at']);
        });
    }
};
