<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pay scale's entry pay (MoM of 2026-10-29): a promotion is only from a lower
 * pay scale to a higher one, and the entry pay is what orders them — "Level-13A"
 * has no whole-number level, but 131100 sits between 123100 and 144200.
 *
 * Backfilled from a label that states its range, "Level-10 (56100-177500)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_scales', function (Blueprint $table): void {
            $table->unsignedInteger('minimum_pay')->nullable()->after('grade_pay');
        });

        foreach (DB::table('pay_scales')->get(['id', 'label']) as $scale) {
            if (preg_match('/\(\s*(\d+)\s*-\s*\d+\s*\)/', (string) $scale->label, $match) === 1) {
                DB::table('pay_scales')->where('id', $scale->id)->update(['minimum_pay' => (int) $match[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pay_scales', function (Blueprint $table): void {
            $table->dropColumn('minimum_pay');
        });
    }
};
