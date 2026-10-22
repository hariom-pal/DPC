<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 4.4 — the department's undertakings, added to the rule 1.1
 * checklist (2026-10-21):
 *
 *   - representations: every representation, complaint or letter received about the
 *     DPC is uploaded with its summary, and none affects the DPC
 *     (`dpc_proposal_representations`);
 *   - original documents: the originals are in each officer's original (Mul) folder;
 *   - Recruitment Rule conformity: every officer meets clauses 8 to 11, and the latest
 *     Recruitment Rule — notified on `rr_notified_on` — is the one the proposal follows.
 *
 * Additive: one nullable column and a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposal_verifications', function (Blueprint $table): void {
            $table->date('rr_notified_on')->nullable();
        });

        Schema::create('dpc_proposal_representations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            $table->string('received_from')->nullable();
            $table->date('received_on')->nullable();
            $table->text('summary');

            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proposal_representations');

        Schema::table('dpc_proposal_verifications', function (Blueprint $table): void {
            $table->dropColumn('rr_notified_on');
        });
    }
};
