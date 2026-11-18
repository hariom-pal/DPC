<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The DPC sitting as a meeting the DPC Chairman starts, and each member's OK on each
 * candidate (business decision, 2026-11-18). Candidates are never called before a
 * DPC, so "Call this candidate" is gone; the calls already recorded stay where they
 * are (dpc_candidate_calls), untouched. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_sitting_meetings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            // The meeting room the portal made; members reach it through the portal.
            $table->string('room', 120);
            $table->string('url', 500);
            $table->foreignId('initiated_by')->constrained('users');
            $table->timestamp('initiated_at');
            $table->timestamps();
            $table->index(['dpc_proposal_id', 'initiated_at']);
        });

        Schema::create('dpc_candidate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('reviewed_at');
            // An OK stops counting — kept, never deleted — when the record it was
            // given on changes: a correction at the sitting, or a return to the AO
            // resubmitted. A fresh OK is then needed on the updated record.
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['dpc_evaluation_row_id', 'user_id', 'invalidated_at'], 'dpc_candidate_reviews_row_user_valid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_candidate_reviews');
        Schema::dropIfExists('dpc_sitting_meetings');
    }
};
