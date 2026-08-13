<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calculation runs and results (PPT S33 CALCULATE, requirements §5.7) —
 * M5 Phase 3.
 *
 * **A calculation is never overwritten.** Recalculating creates a new run; the
 * previous one stands untouched. This is the whole point of the design: the
 * Commission acts on a calculation, and if a promotion is later contested the
 * question is what the engine produced *at that moment*, under the rules in
 * force *then* — not what the same code would produce today.
 *
 * Every run is therefore **self-contained** (architect Decision 1). It stores
 * the complete rule set it ran under, the officer data fingerprint, who ran
 * it, when, and which build of the application did it. Nothing about a stored
 * calculation is re-derived from current configuration, ever.
 *
 * `uuid` is the public identifier (architect directive); the auto-increment
 * `id` stays internal. A sequential id in a legal document leaks how many
 * calculations exist and invites tampering by guessing neighbours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->string('status', 20)->default('draft')->index();

            // Self-containment (Decision 1): the rules this run executed under,
            // preserved in full so the run can be re-executed years later
            // without reading today's configuration.
            $table->string('rule_version', 50);
            $table->json('configuration_snapshot');

            // A content fingerprint of the officer data the run consumed. DPC
            // proposals are not sequentially versioned (M4 design decision), so
            // "proposal version" is expressed as a hash: two runs with the same
            // fingerprint saw identical input, and a different one proves the
            // underlying data changed between them.
            $table->char('proposal_version', 64);

            // Build provenance (architect directive).
            $table->string('application_version', 50);
            $table->string('git_commit_hash', 64);
            $table->timestamp('build_timestamp')->nullable();

            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at');

            // Zone of consideration, stored whole so it explains itself.
            $table->json('zone');

            $table->unsignedInteger('officers_considered')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('main_list_count')->default(0);
            $table->unsignedInteger('waiting_list_count')->default(0);

            // Q5/Q6 are unruled. A run that met a case those rules would decide
            // records it rather than resolving it.
            $table->boolean('has_pending_decisions')->default(false);
            $table->json('pending_decisions')->nullable();

            $table->timestamps();

            $table->index(['dpc_proposal_id', 'executed_at']);
        });

        Schema::create('dpc_calculation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_calculation_run_id')->constrained('dpc_calculation_runs')->cascadeOnDelete();

            // Link back to the source row, plus a snapshot of the officer's
            // identity. The snapshot is what makes a result readable on its
            // own: a result that could only be understood by joining to live
            // data would not survive the data changing.
            $table->foreignId('dpc_evaluation_row_id')->nullable()->constrained('dpc_evaluation_rows')->nullOnDelete();
            $table->unsignedSmallInteger('sn');
            $table->string('officer_name');
            $table->string('employee_number')->nullable();
            $table->string('reservation_category_code', 20);

            $table->json('score_breakdown');
            $table->json('verdict');
            $table->json('super_seat');

            $table->boolean('is_eligible')->default(false);
            $table->boolean('is_super_seat')->default(false);
            $table->boolean('in_zone')->default(false);

            // PPT §6.3 remark codes. A plain string rather than an enum column:
            // main/waiting remarks carry the reservation category code, which
            // is admin-editable master data, not a fixed vocabulary.
            $table->string('remark', 20);
            $table->string('list_type', 10);
            $table->unsignedSmallInteger('list_position')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();

            $table->timestamps();

            $table->unique(['dpc_calculation_run_id', 'sn']);
            $table->index(['dpc_calculation_run_id', 'list_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_calculation_results');
        Schema::dropIfExists('dpc_calculation_runs');
    }
};
