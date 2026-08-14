<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review references and supersession metadata for a generated document —
 * M8 Phase 3 (post-review directives 2 and 3).
 *
 * Every document must permanently reference the **original DPC**, the **review
 * DPC**, the **chain** and **itself**, and a superseded document must carry the
 * **timestamp at which supersession took effect**.
 *
 * `dpc_proceeding_documents` belongs to M7, whose freeze verification must stay
 * empty — and adding a column there would mean either editing a frozen model or
 * writing behind its own immutability guard, which is the tamper vector TD-1
 * exists to describe. So the metadata lives beside the document rather than on
 * it, keyed by **document UUID** as well as by foreign key: a reference record
 * must stay readable without depending on a join surviving.
 *
 * One row per document. It is written when a **Review's** documents are
 * generated, and when a **superseded** DPC's existing documents acquire their
 * supersession timestamp — so both ends of a correction carry the same four
 * identifiers.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_document_review_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // The constraint is named explicitly. Laravel's derived name —
            // `dpc_document_review_references_dpc_proceeding_document_id_foreign`
            // — is 65 characters, and MySQL refuses any identifier over 64
            // (error 1059). SQLite does not, which is why the test suite was
            // green while `migrate:fresh` could not build this table at all.
            $table->foreignId('dpc_proceeding_document_id')
                ->constrained('dpc_proceeding_documents', 'id', 'dpc_document_review_ref_document_fk')
                ->cascadeOnDelete();

            // Directive 3 — the four permanent references.
            $table->uuid('document_uuid');
            $table->uuid('original_dpc_uuid');
            $table->uuid('review_dpc_uuid');
            $table->uuid('chain_uuid');

            $table->unsignedInteger('review_number');

            // The DPC code the department knows the Review by — what a
            // "Superseded By" line has to print (Directive 1).
            $table->string('review_dpc_code')->nullable();

            // Directive 2. Null while the Review is unapproved: a document is
            // not superseded merely because a correction has been filed.
            $table->timestamp('supersession_effective_at')->nullable();

            $table->timestamps();

            $table->unique('dpc_proceeding_document_id', 'dpc_document_review_ref_document_unique');
            $table->index('chain_uuid');
            $table->index('document_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_document_review_references');
    }
};
