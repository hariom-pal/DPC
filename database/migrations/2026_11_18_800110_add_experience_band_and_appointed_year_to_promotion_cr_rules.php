<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion rule for a band of minimum experience, and what "appointed in this
 * year" does under it (business decision, 2026-11-18).
 *
 *  - `max_experience_years`: the rule applies to a DPC whose Pre-DPC minimum
 *    experience is at most this many years. Null is the class pair's general rule,
 *    which is every rule recorded before.
 *  - `appointed_year_is_fit`: a year recorded "appointed in this year" ends the
 *    years asked for — none older is needed — and does not make the officer Unfit.
 *    False on every rule recorded before, so they keep their behaviour.
 *
 * Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_experience_years')->nullable()->after('from_class');
            $table->boolean('appointed_year_is_fit')->default(false)->after('tribal_bonus_applicable');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->dropColumn(['max_experience_years', 'appointed_year_is_fit']);
        });
    }
};
