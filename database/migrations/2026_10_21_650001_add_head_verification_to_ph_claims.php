<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 2.1 (business decisions, 2026-10-21): a PH claim is
 * verified by the department's Administrative Head before the proposal is approved —
 * `ph_verification_status` becomes `verified` (final decision `benefit`) or
 * `not_verified` (final decision `no_benefit`) — and a verified claim takes one of the
 * PH seats the calculation sets aside first.
 *
 * Additive: who verified, when, and the remarks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->foreignId('ph_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ph_verified_at')->nullable();
            $table->text('ph_verification_remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ph_verified_by');
            $table->dropColumn(['ph_verified_at', 'ph_verification_remarks']);
        });
    }
};
