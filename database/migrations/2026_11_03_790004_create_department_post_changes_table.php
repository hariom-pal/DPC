<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post Mapping changes awaiting the Department Admin (UAT, 2026-11-03): "AO k paas
 * post mapping rehne do but wo fir D/A k paas approval k liye jaaye; direct without
 * approval sirf DA kar sakta hai".
 *
 * One row per change the Authorized Officer asks for — a new mapping, an edit, a
 * supersession or a deletion — with the form's values and any Government Order PDF
 * already stored. Nothing reaches `department_posts` until the Department Admin
 * approves; the approval applies the change through the same code the Department
 * Admin's own save uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_post_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            // The mapping changed; null for a new mapping.
            $table->foreignId('department_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            // create, update, supersede, delete.
            $table->string('action', 20);
            // The validated form, files excluded.
            $table->json('payload');
            // Stored Government Order PDFs: [{field, disk, path, original_name, size_bytes, sha256}].
            $table->json('documents')->nullable();
            // pending, approved, rejected.
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            // The mapping the approval produced or changed.
            $table->foreignId('result_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->timestamps();

            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_post_changes');
    }
};
