<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Government Order PDF behind a Department Post Mapping — every version of it.
 *
 * **A row rather than a column, because the requirement is that nothing is lost.**
 * Replacing a scan — a clearer copy, a corrected page, the signed original arriving
 * after a provisional one — must keep the file it replaced. A column holds one
 * path and forgets the last; a table keeps them all and marks which is current.
 *
 * **Two kinds of history, and they are different.** Superseding a *mapping* creates
 * a new `department_posts` row with its own order and its own document. Replacing
 * the *file* on one mapping leaves the mapping alone and adds a row here. Both are
 * "keep the old one", and conflating them would lose one or the other.
 *
 * **The hash is what makes the file evidence.** A Government Order is the legal
 * reference for a mapping, and a stored PDF that cannot be shown to be the one that
 * was uploaded is not a reference to anything. `sha256` is recorded at upload, the
 * same discipline the proceeding and circulation documents already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_post_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('department_post_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 40);
            $table->string('path');

            // What the department called it. Shown on the screen, never used to
            // build a path — a filename from a browser is user input.
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);

            /*
             | Nullable `1`, never `0`, so `unique(department_post_id, is_current)`
             | means one current document and any number of superseded ones.
             */
            $table->unsignedTinyInteger('is_current')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_post_id', 'is_current'], 'department_post_documents_current_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_post_documents');
    }
};
