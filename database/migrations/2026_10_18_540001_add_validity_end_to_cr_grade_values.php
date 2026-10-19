<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Eligible CR Grade is valid for a window: from `effective_from` to `effective_to`
 * (both inclusive; open-ended while `effective_to` is null). Deactivating a grade sets
 * the end date, with who did it, when and why; nothing is deleted, so which grades
 * applied to a DPC year stays answerable.
 *
 * Additive: four nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cr_grade_values', function (Blueprint $table): void {
            $table->date('effective_to')->nullable();
            $table->string('deactivation_reason', 255)->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cr_grade_values', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['effective_to', 'deactivation_reason', 'deactivated_at']);
        });
    }
};
