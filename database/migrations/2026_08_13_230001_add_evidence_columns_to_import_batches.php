<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import evidence retention (architect directives, 2026-08-13) — M4 Phase 7.
 *
 * Directive 1: every batch must permanently preserve the original uploaded
 * spreadsheet, its CSV error report, and its import log. The original file and
 * the log (`import_rows`) were already retained by Phase 6; the error report
 * was generated on demand and never stored, so `error_report_path` persists it
 * at preview time. What the officer downloads is now the same artefact that
 * was produced when the batch was validated, not a fresh render that could
 * differ if validation rules change later.
 *
 * Directive 2: `file_hash` is the SHA-256 of the uploaded file, for duplicate
 * detection, evidence, file integrity, and audit. Indexed because duplicate
 * detection looks up by hash.
 *
 * Additive only; nothing from Phase 6 is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->char('file_hash', 64)->nullable()->after('stored_path');
            $table->string('error_report_path')->nullable()->after('file_hash');

            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn(['file_hash', 'error_report_path']);
        });
    }
};
