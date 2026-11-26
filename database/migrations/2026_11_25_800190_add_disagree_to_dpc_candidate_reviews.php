<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's Disagree on one candidate (2026-11-25). A member agrees once with the whole
 * DPC — one review per candidate — and may then disagree on any candidate, with a remark
 * that goes to the DPC Chairman. The remark has no length limit, by instruction.
 *
 * Additive: two nullable columns on an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_candidate_reviews', function (Blueprint $table): void {
            $table->timestamp('disagreed_at')->nullable()->after('reviewed_at');
            $table->longText('disagree_remarks')->nullable()->after('disagreed_at');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_candidate_reviews', function (Blueprint $table): void {
            $table->dropColumn(['disagreed_at', 'disagree_remarks']);
        });
    }
};
