<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPC Proposal (PPT S12–S25) — M4 Phase 1: aggregate root + post selection
 * (S14) + workflow + DPC ID generation. Committee, working officers,
 * seniority list, and the evaluation chart are separate child tables added
 * in later M4 phases. `original_dpc_proposal_id`/`review_reason_id` are
 * included now (cheap, already in the approved design) for Review DPC (M8)
 * to reuse this same aggregate without a disruptive later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_id_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_serial')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'year']);
        });

        Schema::create('dpc_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('dpc_type_id')->constrained('dpc_types');
            $table->foreignId('original_dpc_proposal_id')->nullable()->constrained('dpc_proposals')->nullOnDelete();
            $table->foreignId('review_reason_id')->nullable()->constrained('review_reasons')->nullOnDelete();
            $table->foreignId('post_to_cadre_id')->constrained('cadres');
            $table->foreignId('post_from_cadre_id')->constrained('cadres');
            $table->boolean('legal_stay')->default(false);
            $table->date('proposal_date');
            $table->string('status', 20)->default('draft')->index();
            $table->string('dpc_code')->nullable()->unique();
            $table->timestamp('forwarded_at')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_remarks')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proposals');
        Schema::dropIfExists('dpc_id_sequences');
    }
};
