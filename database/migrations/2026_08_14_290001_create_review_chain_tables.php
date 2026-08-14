<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review DPC chain and reasons (PPT S35, S37) — M8 Phase 1.
 *
 * **`dpc_review_links`** records that one DPC reviews another (architect
 * ruling AQ-9: a Review **supersedes** the original — never deletes, never
 * overwrites).
 *
 * The relationship lives in **one row carrying both foreign keys**, which is
 * what makes it bidirectional: "what reviews this?" and "what does this
 * review?" are the same table read from opposite ends. It is deliberately
 * *not* a column on `dpc_proposals` — writing there from M8 would mean editing
 * the frozen M4 model or writing behind its immutability guard, the trap TD-19
 * and TD-21 already record.
 *
 * **`chain_uuid` and `review_number`** (architect Directives 1 and 2) identify
 * the correction chain and the position in it. A Fresh DPC reviewed twice in
 * succession produces reviews numbered 1 and 2 under one chain UUID, so "show
 * me every correction of this promotion" is a single indexed query rather than
 * a recursive walk — and AQ-14's rule, that only the latest may be reviewed,
 * becomes a comparison rather than a traversal.
 *
 * **`superseded_at`** is null until the Review's own result is approved.
 * Raising a Review must not invalidate the original: a Review that is cancelled
 * or never approved leaves the original standing, which is why the link records
 * the *intent* at creation and the *effect* only when it happens.
 *
 * **`dpc_proposal_review_reasons`** exists because S37 renders the reasons as
 * **checkboxes** while M4 prepared a singular `review_reason_id`. Several
 * grounds routinely apply at once — a name omitted from the seniority list is
 * frequently also a roster-point error. The singular column is left unused for
 * reviews rather than repurposed; quietly redefining a frozen column's meaning
 * is worse than leaving it idle.
 *
 * Additive only. No M4/M5/M6/M7 table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_review_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Both ends of the relationship, in one row.
            $table->foreignId('review_dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('original_dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            // Directives 1 and 2.
            $table->uuid('chain_uuid');
            $table->unsignedInteger('review_number');

            // AQ-9. Declared as a column rather than assumed, so a future
            // ruling that some reviews only annotate needs no migration.
            $table->string('effect', 20)->default('supersedes');

            // Null until the Review's result is approved.
            $table->timestamp('superseded_at')->nullable();

            $table->foreignId('established_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('established_by_name');
            $table->timestamp('established_at');

            $table->timestamps();

            // One proposal reviews at most one original.
            $table->unique('review_dpc_proposal_id', 'dpc_review_link_review_unique');
            // AQ-14's rule made structural: an original can be reviewed once.
            $table->unique('original_dpc_proposal_id', 'dpc_review_link_original_unique');
            $table->unique(['chain_uuid', 'review_number'], 'dpc_review_link_chain_number_unique');
            $table->index('chain_uuid');
        });

        Schema::create('dpc_proposal_review_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('review_reason_id')->constrained('review_reasons');

            // Free text against a coded reason, where the department wants to
            // be specific about which officer or which entry was wrong.
            $table->string('detail')->nullable();

            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'review_reason_id'], 'dpc_proposal_review_reason_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proposal_review_reasons');
        Schema::dropIfExists('dpc_review_links');
    }
};
