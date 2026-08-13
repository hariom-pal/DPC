<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPC Committee (PPT S15) — M4 Phase 2. Members reference an existing
 * application user wherever possible (`user_id`); external members (not
 * yet supported as app accounts) are manual entries with `user_id` null.
 * Name/designation/mobile/email are always stored on the row — a snapshot
 * at the time the member was added, not a live join, so the historical
 * committee record for a proposal never silently changes if a user's
 * profile is edited later.
 *
 * `signature_status`/`signed_at`/`signature_reference` are schema
 * preparation for future CSC DigiSign integration (architect directive,
 * 2026-08-13) — unused until that milestone; no signing logic here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('capacity', 20);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('signature_status', 20)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_reference')->nullable();
            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'user_id']);
            $table->index(['dpc_proposal_id', 'capacity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_committee_members');
    }
};
