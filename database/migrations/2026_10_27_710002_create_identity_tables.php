<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity verification history (Phases 2–5, 2026-10-27). Both tables are
 * append-only (model guards); identity values in them are encrypted at rest. No
 * Aadhaar number, Aadhaar document or photograph is ever stored.
 *
 * - `identity_verifications`: one row per outcome — verified, mismatch,
 *   technical failure, consent denied, manual verification — and its revocation.
 * - `identity_corrections`: every change to name, date of birth or gender, by
 *   field, with the reason and (after verification) the supporting document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 30);
            $table->string('outcome', 30);
            $table->json('mismatched_fields')->nullable();
            $table->text('returned_identity')->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_disk', 30)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'outcome', 'created_at'], 'idv_user_outcome_created_index');
        });

        Schema::create('identity_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('field', 30);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_disk', 30)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_corrections');
        Schema::dropIfExists('identity_verifications');
    }
};
