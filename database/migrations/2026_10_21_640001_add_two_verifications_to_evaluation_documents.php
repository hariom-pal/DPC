<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 4.4: two verifications on every uploaded document
 * (business decision, 2026-10-21) — the Authorized Officer's when uploading it, and
 * the Administrative Head's before approving the proposal.
 *
 * Additive: nullable columns. A document uploaded before this was uploaded by the AO,
 * so its upload is its first verification; the AH's is still to come.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_evaluation_documents', function (Blueprint $table): void {
            $table->foreignId('ao_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ao_verified_at')->nullable();
            $table->foreignId('ah_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ah_verified_at')->nullable();
        });

        DB::table('dpc_evaluation_documents')->whereNull('ao_verified_at')->update([
            'ao_verified_by' => DB::raw('uploaded_by'),
            'ao_verified_at' => DB::raw('uploaded_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ao_verified_by');
            $table->dropConstrainedForeignId('ah_verified_by');
            $table->dropColumn(['ao_verified_at', 'ah_verified_at']);
        });
    }
};
