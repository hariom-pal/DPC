<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow Timelines, decided by the CGPSC Administrator per stage (2026-11-17):
 *
 * - `on_expiry`: what happens once the stage's days have passed — `notify` (keep
 *   reminding), `cancel` (the DPC is cancelled) or `forward` (on to the next
 *   officer). The Secretary's stage keeps the MoM's rule: forward to the Chairman.
 * - `reminder_every_days`: how often a reminder goes after the deadline.
 * - `remind_email` / `remind_sms`: by which channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stage_timelines', function (Blueprint $table): void {
            $table->string('on_expiry', 20)->default('notify')->after('days');
            $table->unsignedSmallInteger('reminder_every_days')->default(1)->after('on_expiry');
            $table->boolean('remind_email')->default(true)->after('reminder_every_days');
            $table->boolean('remind_sms')->default(false)->after('remind_email');
        });

        DB::table('workflow_stage_timelines')->where('stage', 'secretary')->update(['on_expiry' => 'forward']);
    }

    public function down(): void
    {
        Schema::table('workflow_stage_timelines', function (Blueprint $table): void {
            $table->dropColumn(['on_expiry', 'reminder_every_days', 'remind_email', 'remind_sms']);
        });
    }
};
