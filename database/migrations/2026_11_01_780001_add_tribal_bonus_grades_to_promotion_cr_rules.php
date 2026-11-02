<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grades at which a promotion rule's tribal posting bonus is given (business
 * decision, 2026-11-01): a CR year served in a tribal area earns the bonus only when
 * its grade's points are in this list.
 *
 * Null on every rule recorded before: those keep `tribal_bonus_skips_extremes`, so a
 * DPC calculated under them scores as it did. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->json('tribal_bonus_grades')->nullable()->after('tribal_bonus_skips_extremes');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->dropColumn('tribal_bonus_grades');
        });
    }
};
