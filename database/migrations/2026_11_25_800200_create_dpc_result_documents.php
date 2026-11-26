<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The result documents (2026-11-25): the Hindi proceedings (कार्यवाही विवरण) of a DPC
 * and one confidential sealed-cover recommendation (गोपनीय बंद लिफाफे की अनुशंसा) for
 * each officer kept in a sealed cover — PDFs made when the result is approved, from a
 * snapshot of the data, and eSigned by every committee member. Once all have signed,
 * each is made again with their signatures on it and is final.
 *
 * Additive: two new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_result_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();
            $table->string('kind', 20);                 // karyavahi | sealed_cover
            $table->string('format', 20)->nullable();   // class_1_to_1 | other
            $table->foreignId('dpc_calculation_result_id')->nullable()->constrained('dpc_calculation_results')->nullOnDelete();
            $table->unsignedInteger('sn')->nullable();
            $table->string('officer_name')->nullable();
            // What the document was made from, so its final signed copy says exactly the same.
            $table->json('data');
            $table->string('disk', 20);
            $table->string('path');
            $table->char('sha256', 64);
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('final_path')->nullable();
            $table->char('final_sha256', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['dpc_proceeding_id', 'kind']);
        });

        Schema::create('dpc_result_document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('member_name');
            $table->string('designation')->nullable();
            // One eSign covers every result document of the DPC: the digest of their digests.
            $table->char('bundle_sha256', 64);
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            $table->timestamp('signed_at');
            $table->timestamps();
            $table->unique(['dpc_proceeding_id', 'user_id'], 'dpc_result_doc_sig_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_result_document_signatures');
        Schema::dropIfExists('dpc_result_documents');
    }
};
