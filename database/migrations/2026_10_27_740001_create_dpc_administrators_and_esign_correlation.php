<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eSign verification (2026-10-27): the name the eSign provider asserted for the
 * authenticated signer, and the one-time reference the request carried, so every
 * signature is checked against its own transaction. A provider transaction
 * reference is accepted once (`signatures_provider_txn_unique`).
 *
 * (The file keeps its name because it has already run; the DPC administrators
 * table it once created was withdrawn on 2026-10-28.)
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->string('signer_name')->nullable()->after('certificate_issuer');
            $table->string('request_reference', 64)->nullable()->after('signer_name');
            $table->unique(['provider', 'transaction_reference'], 'signatures_provider_txn_unique');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropUnique('signatures_provider_txn_unique');
            $table->dropColumn(['signer_name', 'request_reference']);
        });

    }
};
