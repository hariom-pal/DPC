<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a promotion rule's tribal posting bonus is given (business decision,
 * 2026-10-21): for each CR year served in a tribal area, and — when the rule says
 * so — not for a year at the highest or the lowest grade.
 *
 * `tribal_bonus_basis` is `year` (each tribal CR year, the default) or `officer` (the
 * PPT's single bonus for any tribal service, kept for the PPT rule set).
 * Additive: two columns with defaults; existing rules take the decided terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->string('tribal_bonus_basis', 10)->default('year')->after('tribal_bonus');
            $table->boolean('tribal_bonus_skips_extremes')->default(true)->after('tribal_bonus_basis');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->dropColumn(['tribal_bonus_basis', 'tribal_bonus_skips_extremes']);
        });
    }
};
