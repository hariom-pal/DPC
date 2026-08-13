<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPC scheduling by the allotted Member (PPT S30) — M6 Phase 2.
 *
 * **Append-only, like allotments** (architect directive). Re-scheduling —
 * Phase 3 — will write a new row and mark this one superseded rather than
 * editing the date, because S30 displays the previous date and time beside
 * the new one, which is only possible if the old row survives.
 *
 * `superseded_by_id` and `reschedule_reason` are part of that append-only
 * design rather than spare columns: they are the mechanism that makes
 * overwriting impossible. Phase 2 never populates them — a Phase 2 schedule
 * is always the first and only one — and Phase 3 does.
 *
 * `accepted_at` is added to `dpc_allotments` here because acceptance is a
 * Phase 2 concern; Phase 1 had no state that could record it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_allotments', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('default_dpc_date');
        });

        Schema::create('dpc_schedules', function (Blueprint $table) {
            $table->id();

            // Public identifier — standing ruling: never expose sequential ids.
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_allotment_id')->constrained('dpc_allotments')->cascadeOnDelete();

            $table->date('dpc_date');
            $table->time('dpc_time');

            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');

            // Phase 3 populates these; the columns exist now because they are
            // what makes the history append-only rather than editable.
            $table->foreignId('superseded_by_id')->nullable()->constrained('dpc_schedules')->nullOnDelete();
            $table->text('reschedule_reason')->nullable();

            $table->timestamps();

            $table->index(['dpc_allotment_id', 'dpc_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_schedules');

        Schema::table('dpc_allotments', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
    }
};
