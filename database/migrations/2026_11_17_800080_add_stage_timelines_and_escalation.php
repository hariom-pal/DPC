<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoM of 16 November 2026, points 6 and 7.
 *
 * - `workflow_stage_timelines`: the days the CGPSC Administrator allows each stage
 *   of a DPC (AO, AH, Secretary, Chairman, Committee). Empty (no deadline) until the
 *   Administrator sets it — the MoM's figures were examples only.
 * - `dpc_stage_reminders`: one row per reminder sent, so a stage is reminded once a
 *   day at most and the history is kept.
 * - `dpc_proposals.secretary_escalated_at`: a DPC sent on to the Chairman because
 *   the Secretary's deadline passed (point 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stage_timelines', function (Blueprint $table): void {
            $table->id();
            $table->string('stage', 30)->unique();
            $table->unsignedSmallInteger('days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['ao', 'ah', 'secretary', 'chairman', 'committee'] as $stage) {
            DB::table('workflow_stage_timelines')->insert(['stage' => $stage, 'days' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('dpc_stage_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('kind', 20);
            $table->date('sent_on');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'stage', 'kind', 'sent_on'], 'dpc_stage_reminder_once_a_day');
        });

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->timestamp('secretary_escalated_at')->nullable()->after('secretary_signature_id');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('secretary_escalated_at');
        });
        Schema::dropIfExists('dpc_stage_reminders');
        Schema::dropIfExists('workflow_stage_timelines');
    }
};
