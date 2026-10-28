<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-proposal allotment of a Pre-DPC or a DPC proposal to one Authorized Officer
 * and one Administrative Head, by the Department Admin (business decision,
 * 2026-10-26; docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).
 *
 * Append-only: a reassignment ends the current row (`ended_at`) and writes a new
 * one, so the rows of a proposal are its allotment history. Exactly one of the two
 * proposal keys is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_allotments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('pre_dpc_proposal_id')->nullable()->constrained('pre_dpc_proposals')->cascadeOnDelete();
            $table->foreignId('dpc_proposal_id')->nullable()->constrained('dpc_proposals')->cascadeOnDelete();

            $table->foreignId('ao_user_id')->constrained('users');
            $table->foreignId('ah_user_id')->constrained('users');

            $table->foreignId('allotted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allotted_at');
            $table->text('reason')->nullable();

            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['pre_dpc_proposal_id', 'ended_at']);
            $table->index(['dpc_proposal_id', 'ended_at']);
            $table->index(['ao_user_id', 'ended_at']);
            $table->index(['ah_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_allotments');
    }
};
