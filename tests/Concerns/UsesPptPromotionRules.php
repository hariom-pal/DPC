<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Master\Models\CrGradeValue;
use Database\Seeders\Support\PptPromotionRules;
use Illuminate\Support\Facades\Schema;

/**
 * A DPC is calculated under the Class Master promotion rule and the Eligible CR
 * Grades' points (2026-10-21). The calculation tests pin the PPT's own rule set —
 * marks 4/3/2/1/0 and qualifying totals 15/13/11/10 — so this records exactly that
 * as master data before each test: the PPT's worked examples keep their numbers,
 * and the engine reads them from the masters it reads in production.
 *
 * A test about the rules themselves changes or deletes these rows.
 */
trait UsesPptPromotionRules
{
    protected function setUpUsesPptPromotionRules(): void
    {
        if (! Schema::hasTable('promotion_cr_rules')) {
            return;
        }

        // Two passes: value and date are unique together, and 4/3/2/1/0 overlaps 9/7/5/3/1.
        $marks = ['क+' => 4, 'क' => 3, 'ख' => 2, 'ग' => 1, 'घ' => 0];

        foreach ($marks as $code => $mark) {
            CrGradeValue::query()->where('grade_code', $code)->update(['value' => 1000 + $mark]);
        }

        foreach ($marks as $code => $mark) {
            CrGradeValue::query()->where('grade_code', $code)->update(['value' => $mark]);
        }

        PptPromotionRules::record(['I-I' => 15, 'II-I' => 13, 'II-II' => 11, 'default' => 10], tribalBonus: 1, superSeatPoints: 4);
    }
}
