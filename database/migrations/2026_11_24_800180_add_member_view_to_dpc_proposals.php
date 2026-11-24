<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-24: the DPC Chairman opens the DPC to the committee members ("view mode")
 * within the days the Administrator sets after the allotment; past them it opens on
 * its own. Recorded against the allotment it was opened under, so a re-allotment
 * starts afresh without anything being cleared. Additive: nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->foreignId('member_view_allotment_id')->nullable()->constrained('dpc_allotments')->nullOnDelete();
            $table->timestamp('member_view_enabled_at')->nullable();
            $table->foreignId('member_view_enabled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('member_view_enabled_by');
            $table->dropColumn('member_view_enabled_at');
            $table->dropConstrainedForeignId('member_view_allotment_id');
        });
    }
};
