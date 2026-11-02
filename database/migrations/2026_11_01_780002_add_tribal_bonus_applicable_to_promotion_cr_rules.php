<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a promotion rule gives the tribal posting bonus at all (business decision,
 * 2026-11-01): an explicit Yes/No beside the points and the grades.
 *
 * Every rule recorded before reads Yes exactly when it carried a bonus, so the
 * calculations made under it are unchanged. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->boolean('tribal_bonus_applicable')->default(false)->after('tribal_bonus');
        });

        DB::table('promotion_cr_rules')->where('tribal_bonus', '>', 0)->update(['tribal_bonus_applicable' => true]);
    }

    public function down(): void
    {
        Schema::table('promotion_cr_rules', function (Blueprint $table): void {
            $table->dropColumn('tribal_bonus_applicable');
        });
    }
};
