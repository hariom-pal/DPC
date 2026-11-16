<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The missing-record reasons of the CR and of the Property Return are kept apart
 * (2026-11-16): the Administrator marks each reason as offered for a CR, for a
 * Property Return, or for both. Every existing reason starts as both, so nothing
 * offered today disappears until the Administrator says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_reasons', function (Blueprint $table): void {
            $table->boolean('for_cr')->default(true)->after('makes_unfit');
            $table->boolean('for_property')->default(true)->after('for_cr');
        });
    }

    public function down(): void
    {
        Schema::table('record_reasons', function (Blueprint $table): void {
            $table->dropColumn(['for_cr', 'for_property']);
        });
    }
};
