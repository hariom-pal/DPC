<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 1.2: a vacancy arising from an incumbent's promotion to a
 * higher post is counted only with an undertaking that the higher post's DPC
 * proposal has been forwarded to the CGPSC, or the promotion finalized pending the
 * order. When the officer gave it is recorded on the entry.
 *
 * Additive: one nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->timestamp('higher_post_undertaking_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn('higher_post_undertaking_at');
        });
    }
};
