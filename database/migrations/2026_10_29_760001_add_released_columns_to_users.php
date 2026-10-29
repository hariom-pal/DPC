<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Released officers (2026-10-29): a Department Admin releases a transferred AO/AH
 * from the department, and another department may then pick the officer up.
 * Set on release, cleared when the officer is posted again.
 *
 * Additive: nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('released_at')->nullable()->after('identity_verification_method');
            $table->foreignId('released_from_department_id')->nullable()->after('released_at')->constrained('departments')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->after('released_from_department_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['released_from_department_id']);
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'released_from_department_id', 'released_by']);
        });
    }
};
