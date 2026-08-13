<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel import staging (PPT S16–S18 upload templates) — M4 Phase 6.
 *
 * The architect directive is a staged pipeline: Preview -> Validation ->
 * Error Report -> Commit. These two tables are what makes the "preview"
 * stage possible — an uploaded file is parsed and validated into
 * `import_rows` and nothing touches the proposal until the officer commits
 * the batch. A rejected row is kept, with its errors, so the officer can
 * download an error report and fix the source file.
 *
 * `import_rows.payload` holds the row already mapped into the exact array
 * shape the DpcProposalService save methods accept, so committing a batch is
 * a straight hand-off to the existing service layer with no second copy of
 * the persistence logic (architect directive: never duplicate business
 * logic).
 *
 * Batches are retained after commit: for a promotion process contested in
 * CAT/HC, "which file produced this seniority list, uploaded by whom, and
 * what was rejected" is part of the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->string('template', 40);
            $table->string('status', 20)->default('pending')->index();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'template']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();

            // Line number in the source spreadsheet, so an error report points
            // the officer at the row they actually have to fix.
            $table->unsignedInteger('row_number');

            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->boolean('is_valid')->default(false);
            $table->timestamps();

            $table->index(['import_batch_id', 'is_valid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
