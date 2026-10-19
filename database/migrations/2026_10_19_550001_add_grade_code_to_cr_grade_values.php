<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CR grade an Eligible CR Grade value stands for (Coder Instructions rule 3.4:
 * क+ = 9, क = 7, ख = 5, ग = 3, घ = 1), so a CR recorded as a grade has points that a
 * Class Master promotion rule can be checked against.
 *
 * Additive: one nullable column. The five rule-3.4 rows seeded by migration 520001 get
 * their codes; any other row is left for the Administrator.
 */
return new class extends Migration
{
    private const CODES = [9 => 'क+', 7 => 'क', 5 => 'ख', 3 => 'ग', 1 => 'घ'];

    public function up(): void
    {
        Schema::table('cr_grade_values', function (Blueprint $table): void {
            $table->string('grade_code', 10)->nullable();
        });

        foreach (self::CODES as $value => $code) {
            DB::table('cr_grade_values')
                ->where('value', $value)
                ->whereNull('grade_code')
                ->where('remarks', 'like', '%Coder Instructions rule 3.4%')
                ->update(['grade_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('cr_grade_values', function (Blueprint $table): void {
            $table->dropColumn('grade_code');
        });
    }
};
