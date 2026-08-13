<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPC allotment by the CGPSC Chairman (PPT S28) — M6 Phase 1.
 *
 * **Append-only. An allotment is never overwritten** (architect directive).
 * Re-allotting creates a new row and marks the previous one superseded, so
 * "who was this allotted to, and when did that change" is answerable from the
 * data rather than lost. A mutable `allotted_to` column could not answer it.
 *
 * The CGPSC-side state deliberately does **not** appear as a column on
 * `dpc_proposals`: M4 is architecture-frozen, and duplicating state onto it
 * would create two sources of truth that can disagree. The proposal's current
 * allotment is its newest row here with no successor.
 *
 * `default_dpc_date` is manual Chairman input (architect Decision 2) — never
 * derived from the proposal date. It is nullable because requiring it would
 * itself be an invented business rule; the PPT shows it populated but states
 * no rule that it must be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_allotments', function (Blueprint $table) {
            $table->id();

            // Public identifier — standing ruling: never expose sequential ids.
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            // The Chairman may allot to a Member or to himself (S28).
            $table->foreignId('allotted_to')->constrained('users');
            $table->foreignId('allotted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allotted_at');

            $table->date('default_dpc_date')->nullable();

            $table->string('status', 20)->default('pending')->index();

            // Set when this allotment is replaced by a re-allotment. The chain
            // is the history; nothing is edited in place.
            $table->foreignId('superseded_by_id')->nullable()->constrained('dpc_allotments')->nullOnDelete();
            $table->text('reallotment_reason')->nullable();

            $table->timestamps();

            $table->index(['dpc_proposal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_allotments');
    }
};
