<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The circulation note and the revised promotion list — the prescribed form
 * S45 calls *"निर्धारित प्रारुप"* (PPT S45, S48; C3/C7) — M9 Phase 3.
 *
 * **M9's own table, by architect ruling (AQ-22, 2026-08-16):** *"M9 owns its
 * own document type, document table and document renderer. M7's frozen
 * `DocumentType` enum, `ProceedingDocumentService` and document pipeline SHALL
 * NOT be opened or modified."*
 *
 * The enum was the least of the coupling. **Every M7 entry point is hard-typed
 * to `DpcProceeding`** — `generate()`, `publish()`, `render()` — and a
 * Circulation has no proceeding of its own (AD-24). Reusing M7's pipeline
 * would have meant filing the note as a document *of the original DPC*, under
 * M7's versioning and `verify()`, where a reader could not tell it apart; or
 * adding a **fifth decorator** to a container graph that has already cycled
 * once. Both are foreclosed.
 *
 * **What is reused is `SignatureService` and the pattern, never M7's classes.**
 *
 * The columns deliberately mirror `dpc_proceeding_documents` in shape, because
 * the duplication AQ-22 accepts is mitigated by asserting **the same
 * properties** M7 asserts — stored bytes never rewritten, SHA-256 over stored
 * bytes, `verify()` against the stored artefact — not by sharing its code.
 *
 * **Permanent references are stored as UUID strings, not foreign keys**, for
 * M7's own reason: a legal document must stay readable and verifiable even if
 * a row it points at is later archived, and a UUID does not depend on a join
 * surviving.
 *
 * **Foreign-key names are explicit and inside MySQL's 64-character limit.**
 * The longest here is `fk_dpc_circ_doc_circulation` (27).
 *
 * Additive only. No M4, M5, M6, M7 or M8 table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_circulation_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_circulation_id')
                ->constrained('dpc_circulations', 'id', 'fk_dpc_circ_doc_circulation')
                ->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('status', 20)->default('pending');

            // Permanent references — what this document is *about*, readable
            // without a join. The circulation, the DPC it resolves, and the
            // calculation the promotion rests on.
            $table->uuid('circulation_uuid');
            $table->uuid('origin_proposal_uuid');
            $table->uuid('origin_calculation_uuid')->nullable();
            $table->string('circulation_code')->nullable();
            $table->string('origin_dpc_code')->nullable();

            $table->unsignedInteger('document_version')->default(1);
            $table->string('generator_version', 50);
            // A label somebody has to remember to bump is weaker evidence than
            // a commit hash. For a document produced in evidence years later,
            // the hash is what lets the exact renderer be checked out and re-run.
            $table->string('generator_commit_hash', 64)->nullable();

            $table->timestamp('generated_at')->nullable();

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->char('content_hash', 64)->nullable();

            $table->foreignId('signature_id')->nullable()
                ->constrained('signatures', 'id', 'fk_dpc_circ_doc_signature')
                ->nullOnDelete();

            // §23.1 — publication is refused while BD-14 is unresolved. The
            // document is generated, stored, hashed, signed and verifiable;
            // it is simply not released to the department.
            $table->timestamp('published_at')->nullable();

            $table->text('failure_reason')->nullable();

            $table->foreignId('generated_by')->nullable()
                ->constrained('users', 'id', 'fk_dpc_circ_doc_generated_by')
                ->nullOnDelete();
            $table->string('generated_by_name')->nullable();

            $table->timestamps();

            $table->index(['dpc_circulation_id', 'type'], 'dpc_circ_doc_circulation_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_circulation_documents');
    }
};
