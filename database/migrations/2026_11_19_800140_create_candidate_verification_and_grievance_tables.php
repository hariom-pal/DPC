<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate Verification & Grievance (2026-11-19).
 *
 * One verification cycle per DPC, opened by the AH's approval; one participant per
 * officer on its Seniority List; a decision per section (four); at most one grievance
 * per section, all submitted together under one eSign; the AH's decision on each; the
 * AO's corrections the AH opens; the versioned snapshots a correction makes; and the
 * delivery of every message it sends. Nothing here copies an employee, a DPC or a CR /
 * Integrity / Property File record: they are referenced, never duplicated.
 *
 * The stage joins the Administrator's Workflow Stage Timelines with no days set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_verification_cycles', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            // One per DPC: a candidate's single grievance submission is per DPC.
            $table->foreignId('dpc_proposal_id')->unique()->constrained('dpc_proposals')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('approved_at');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // The days applied and the deadline they gave, kept: a later change of the
            // Administrator's days never moves an open cycle's deadline.
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('deadline_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->string('routed_to', 20)->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('grievance_count')->default(0);
            $table->timestamps();
        });

        Schema::create('dpc_verification_participants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('dpc_verification_cycle_id')->constrained('dpc_verification_cycles')->cascadeOnDelete();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->string('status', 24)->default('not_started')->index();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            // What the candidate signed, as signed: never rewritten.
            $table->json('submitted_snapshot')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['dpc_verification_cycle_id', 'dpc_evaluation_row_id'], 'dpc_verif_participant_once');
        });

        Schema::create('dpc_verification_decisions', function (Blueprint $table): void {
            $table->id();
            // Named: the derived name passes MySQL's 64 characters.
            $table->unsignedBigInteger('dpc_verification_participant_id');
            $table->foreign('dpc_verification_participant_id', 'dpc_verif_decision_participant_fk')->references('id')->on('dpc_verification_participants')->cascadeOnDelete();
            $table->string('section', 12);
            $table->string('decision', 12);
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['dpc_verification_participant_id', 'section'], 'dpc_verif_decision_once');
        });

        Schema::create('dpc_grievances', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('dpc_verification_participant_id')->constrained('dpc_verification_participants')->cascadeOnDelete();
            $table->foreignId('dpc_verification_cycle_id')->constrained('dpc_verification_cycles')->cascadeOnDelete();
            $table->string('section', 12);
            $table->string('category', 40);
            $table->unsignedSmallInteger('related_year')->nullable();
            $table->text('remark');
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamp('undertaking_accepted_at');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('resolution_remark')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One grievance a section: a candidate may raise them in several sections.
            $table->unique(['dpc_verification_participant_id', 'section'], 'dpc_grievance_one_per_section');
            $table->index(['dpc_verification_cycle_id', 'status']);
        });

        Schema::create('dpc_grievance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_grievance_id')->constrained('dpc_grievances')->cascadeOnDelete();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->string('section', 12);
            $table->text('instructions');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->text('ao_remarks')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 12)->default('open')->index();
            $table->timestamps();

            $table->index(['dpc_evaluation_row_id', 'status']);
        });

        Schema::create('dpc_verification_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dpc_verification_participant_id');
            $table->foreign('dpc_verification_participant_id', 'dpc_verif_snapshot_participant_fk')->references('id')->on('dpc_verification_participants')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('reason', 40);
            $table->foreignId('dpc_grievance_id')->nullable()->constrained('dpc_grievances')->nullOnDelete();
            $table->json('snapshot');
            $table->string('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dpc_verification_participant_id', 'version'], 'dpc_verif_snapshot_version');
        });

        Schema::create('dpc_verification_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_verification_cycle_id')->constrained('dpc_verification_cycles')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('channel', 8);
            // participant:ID or user:ID — whom it is for, with the reference that makes it once only.
            $table->string('recipient', 64);
            $table->string('reference', 64)->default('');
            $table->string('destination', 255)->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['dpc_verification_cycle_id', 'kind', 'channel', 'recipient', 'reference'], 'dpc_verif_notification_once');
        });

        // The stage joins the Administrator's timelines, no days set until they set them.
        if (DB::table('workflow_stage_timelines')->where('stage', 'candidate_verification')->doesntExist()) {
            DB::table('workflow_stage_timelines')->insert(['stage' => 'candidate_verification', 'days' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_stage_timelines')->where('stage', 'candidate_verification')->delete();
        Schema::dropIfExists('dpc_verification_notifications');
        Schema::dropIfExists('dpc_verification_snapshots');
        Schema::dropIfExists('dpc_grievance_corrections');
        Schema::dropIfExists('dpc_grievances');
        Schema::dropIfExists('dpc_verification_decisions');
        Schema::dropIfExists('dpc_verification_participants');
        Schema::dropIfExists('dpc_verification_cycles');
    }
};
