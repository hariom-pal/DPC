<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section-wise revision of a finalized vacancy record (2026-11-13): the AH unlocks a
 * section, the AO edits only the unlocked sections and submits, and the AH approves
 * (the revision is then finalized) or returns it with remarks.
 *
 * `unlocked_sections` null means every section is editable — a first draft, or a
 * revision opened before this flow existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->json('unlocked_sections')->nullable()->after('revision_reason');
            $table->foreignId('submitted_by')->nullable()->after('unlocked_sections')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->text('return_remarks')->nullable()->after('submitted_at');
            $table->timestamp('returned_at')->nullable()->after('return_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['unlocked_sections', 'submitted_at', 'return_remarks', 'returned_at']);
        });
    }
};
