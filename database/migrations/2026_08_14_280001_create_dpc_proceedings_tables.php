<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPC proceedings and Commission-side overrides (PPT S31–S34) — M7 Phase 1.
 *
 * **Additive only.** Nothing in M4, M5 or M6 is modified: those milestones are
 * frozen, and the whole point of this design is that the proceeding *reads*
 * the department's approved chart and never writes to it (architect ruling,
 * AQ-1). A `git diff v0.6.0-m6-complete` over `app/Domain/{Dpc,Eligibility,
 * Cgpsc}` stays empty for the life of M7.
 *
 * Two tables:
 *
 * `dpc_proceedings` — the sitting itself. One row per DPC sitting, frozen once
 * it reaches a terminal state.
 *
 * `dpc_proceeding_overrides` — Commission corrections, **append-only**. A
 * correction never touches `dpc_evaluation_rows`; it records what the value
 * was, what it became, why, and who changed it. That is what keeps the
 * department's submission and the Commission's correction separately visible,
 * which is precisely the question a tribunal asks. Phase 1 creates the table
 * and its model; Phase 2 records into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_proceedings', function (Blueprint $table) {
            $table->id();

            // Public identifier (architect Directive 1). Sequential ids never
            // leave the database — a legal document must not leak how many
            // proceedings exist, nor invite guessing at neighbours.
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            // The slot it was held under. Nullable because a schedule may be
            // superseded later; the proceeding must remain readable regardless.
            $table->foreignId('dpc_schedule_id')->nullable()->constrained('dpc_schedules')->nullOnDelete();

            $table->string('status', 20)->default('in_proceeding')->index();

            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();

            // Actor snapshot (M6 directive). `conducted_by` is a *reference* —
            // it tells you who that user is now. This tells you who conducted
            // the sitting *then*, and the two diverge exactly where it counts:
            // a renamed user, or one since removed.
            $table->string('conducted_by_name');

            // S31's evidence. The proposal content fingerprint at the moment
            // the sitting opened, so a later claim that department data changed
            // between forwarding and the DPC date is answerable from stored
            // evidence rather than by argument.
            $table->char('proposal_version', 64);

            // The actual opening time is always recorded, whether or not it
            // matches the scheduled slot (architect ruling, AQ-5).
            $table->timestamp('opened_at');

            // S34's heading year, captured rather than derived (architect
            // ruling, AQ-7): the vacancy position year and the proposal year
            // are not the same thing, so deriving it would be silently wrong.
            // Phase 3 populates it at approval.
            $table->unsignedSmallInteger('promotion_as_on_year')->nullable();

            // Phase 3 pins exactly one calculation run at approval; Phase 4
            // attaches the signature. The columns exist now because they are
            // what makes the record terminal rather than editable.
            $table->foreignId('final_calculation_run_id')->nullable()
                ->constrained('dpc_calculation_runs')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['dpc_proposal_id', 'opened_at']);
        });

        Schema::create('dpc_proceeding_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();

            // The M4 row being corrected — read, never written.
            $table->foreignId('dpc_evaluation_row_id')->nullable()
                ->constrained('dpc_evaluation_rows')->nullOnDelete();

            // Snapshots, so the record stays readable if the source row goes.
            $table->unsignedSmallInteger('sn');
            $table->string('officer_name');

            $table->string('field', 50);
            $table->unsignedSmallInteger('year')->nullable();

            // Both values are recorded (architect Directive 2, replayable
            // overrides). Storing only the new value would make the correction
            // history reconstructible solely by replaying M4 and every prior
            // override in order — which is exactly the dependency the timeline
            // is not permitted to have.
            $table->string('previous_value')->nullable();
            $table->string('new_value')->nullable();

            $table->text('reason');

            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('applied_by_name');
            $table->timestamp('applied_at');

            // Append-only: a later correction supersedes rather than overwrites.
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('dpc_proceeding_overrides')->nullOnDelete();

            $table->timestamps();

            $table->index(['dpc_proceeding_id', 'sn']);
            $table->index(['dpc_proceeding_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proceeding_overrides');
        Schema::dropIfExists('dpc_proceedings');
    }
};
