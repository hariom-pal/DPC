<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1's question (UAT, 2026-11-02): "Is this post only for deputation?" A post kept
 * for deputation goes to the next deputationist, so its officer's return is not a
 * promotion vacancy. Nullable: rows entered before were never asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->boolean('deputation_only_post')->nullable()->after('deputation_from');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn('deputation_only_post');
        });
    }
};
