<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion rule qualifies a candidate on the **total** of the CR years' points
 * (business decision, 2026-10-21), which the DPC calculation now reads.
 *
 * Additive: one nullable column. Each existing rule's total is its recorded average
 * over its CR years, so a rule keeps the standard it was recorded with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('qualifying_total')->nullable()->after('property_years');
        });

        DB::table('promotion_cr_rules')
            ->whereNull('qualifying_total')
            ->update(['qualifying_total' => DB::raw('ROUND(qualifying_average * cr_years)')]);
    }

    public function down(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->dropColumn('qualifying_total');
        });
    }
};
