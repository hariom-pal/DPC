<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Eligible CR Grades named by the Coder Instructions, rule 3.4 (page 4):
 *
 * > उत्कृष्ट (क+) = 9, बहुत अच्छा (क) = 7, अच्छा (ख) = 5, औसत (ग) = 3, खराब (घ) = 1
 *
 * Valid from 1 January 2019 — the PDF gives no date, and rule 1.4 starts this
 * software's scope at 2019. Inserted only where the value is not already recorded,
 * so an Administrator's own entries are never duplicated or changed.
 */
return new class extends Migration
{
    private const EFFECTIVE_FROM = '2019-01-01';

    /**
     * @var list<array{0: int, 1: string}>
     */
    private const GRADES = [
        [9, 'क+ — उत्कृष्ट / Outstanding (Coder Instructions rule 3.4)'],
        [7, 'क — बहुत अच्छा / Very Good (Coder Instructions rule 3.4)'],
        [5, 'ख — अच्छा / Good (Coder Instructions rule 3.4)'],
        [3, 'ग — औसत / Average (Coder Instructions rule 3.4)'],
        [1, 'घ — खराब / Poor (Coder Instructions rule 3.4)'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GRADES as [$value, $remarks]) {
            if (DB::table('cr_grade_values')->where('value', $value)->exists()) {
                continue;
            }

            DB::table('cr_grade_values')->insert([
                'value' => $value,
                'effective_from' => self::EFFECTIVE_FROM,
                'remarks' => $remarks,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only the rows this migration wrote — same value, date and remarks.
        foreach (self::GRADES as [$value, $remarks]) {
            DB::table('cr_grade_values')
                ->where('value', $value)
                ->whereDate('effective_from', self::EFFECTIVE_FROM)
                ->where('remarks', $remarks)
                ->delete();
        }
    }
};
