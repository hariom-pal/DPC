<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to open the Self-Inspection screen (PPT S49–S51) — M10.
 *
 * **Additive only.** No existing table is altered and no column is dropped, so
 * no frozen milestone is touched.
 *
 * **Why a table rather than a log line.** This is the one screen in the system
 * an *unauthenticated* caller can reach with officer data behind it, and the
 * factors that open it — a name and two dates — are all present on a departmental
 * seniority list. Two things follow: a failed attempt has to be recorded as
 * evidence of someone trying, and a successful one has to be recorded as evidence
 * of who saw what. A log file is rotated and is not queryable beside the
 * proposal it concerns; the first time anyone wants this is a contested
 * promotion, which is the worst moment to discover it was only ever a log line
 * (the same argument that put the audit-trail UI in M11).
 *
 * **`dpc_evaluation_row_id` is nullable on purpose.** A failed attempt matched
 * nothing, so there is no row to point at — and that record is the more
 * important of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_inspection_accesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // What the caller claimed. Stored as the normalised name only — not
            // the raw factor answers: recording a failed guess at somebody's
            // date of birth would make this table a place to look them up.
            $table->string('claimed_name');

            $table->boolean('matched');

            // Present only on a match.
            $table->foreignId('dpc_evaluation_row_id')->nullable()
                ->constrained('dpc_evaluation_rows')->nullOnDelete();
            $table->foreignId('dpc_proposal_id')->nullable()
                ->constrained('dpc_proposals')->nullOnDelete();

            // Which mechanism was in force, so a later ruling on BD-19 can be
            // told apart from what came before it.
            $table->string('auth_driver', 32);

            // Which factors were required at the time. The configured set can
            // change, and "this attempt passed" means nothing without it.
            $table->json('factors_required');

            // `action` distinguishes an identification attempt from a view and
            // from a print, because S51's PRINT produces a document an officer
            // may later present.
            $table->string('action', 32);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['claimed_name', 'created_at']);
            $table->index(['dpc_evaluation_row_id', 'created_at'], 'self_inspection_row_created_index');
            $table->index(['matched', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_inspection_accesses');
    }
};
