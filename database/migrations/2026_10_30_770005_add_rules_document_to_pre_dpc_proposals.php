<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Adyatan Vibhagiy Bharti Niyam (updated departmental recruitment rules) a
 * Pre-DPC cites, as an uploaded PDF, and the preparer's declaration with each
 * save (2026-10-30). Additive: nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->string('rules_document_disk', 40)->nullable()->after('effective_from');
            $table->string('rules_document_path')->nullable()->after('rules_document_disk');
            $table->string('rules_document_name')->nullable()->after('rules_document_path');
            $table->char('rules_document_sha256', 64)->nullable()->after('rules_document_name');
            // The preparer's declaration, made with the last save.
            $table->timestamp('prepared_declaration_at')->nullable()->after('rules_document_sha256');
            $table->foreignId('prepared_declaration_by')->nullable()->after('prepared_declaration_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepared_declaration_by');
            $table->dropColumn(['rules_document_disk', 'rules_document_path', 'rules_document_name', 'rules_document_sha256', 'prepared_declaration_at']);
        });
    }
};
