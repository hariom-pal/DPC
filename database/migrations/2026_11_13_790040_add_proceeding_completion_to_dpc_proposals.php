<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AO marks the DPC Proceeding Complete (MoM of 12 November 2026, point 4): only
 * once its promotion orders are issued, and for good — the DPC is then Closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->timestamp('proceeding_completed_at')->nullable()->after('cancelled_at');
            $table->foreignId('proceeding_completed_by')->nullable()->after('proceeding_completed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proceeding_completed_by');
            $table->dropColumn('proceeding_completed_at');
        });
    }
};
