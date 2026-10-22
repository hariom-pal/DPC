<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Domain\Master\Models\PromotionCrRule;

/**
 * The PPT's promotion rules (requirements §5) recorded as Class Master promotion
 * rules, for every class pair: the last five CR years, a qualifying total per pair,
 * a tribal bonus and a Super Seat — no per-year minimum and no mandatory year, so a
 * DPC calculates exactly as the configured PPT rules did.
 *
 * Used where a DPC is calculated without an Administrator to record the rules: the
 * demonstration data and the calculation tests.
 */
final class PptPromotionRules
{
    /** The pairs the Class Master allows: a class from itself or the class below it. */
    public const PAIRS = ['I-I', 'II-I', 'II-II', 'III-II', 'III-III'];

    /**
     * @param  array<string, int>  $totals  pair ("from-to") => qualifying total; a pair not named takes `default`
     */
    public static function record(array $totals, int $tribalBonus, ?int $superSeatPoints, int $fromYear = 2000): void
    {
        foreach (self::PAIRS as $pair) {
            [$from, $to] = explode('-', $pair);

            if (PromotionCrRule::query()->where('to_class', $to)->where('from_class', $from)->exists()) {
                continue;
            }

            $total = $totals[$pair] ?? $totals['default'];

            $rule = PromotionCrRule::query()->create([
                'to_class' => $to,
                'from_class' => $from,
                'effective_from_year' => $fromYear,
                'cr_years' => 5,
                'loyalty_years' => 0,
                'property_years' => 0,
                'qualifying_total' => $total,
                'qualifying_average' => round($total / 5, 2),
                'super_seat_grade' => $superSeatPoints,
                'tribal_bonus' => $tribalBonus,
                // PPT §5.2: one bonus for any tribal service.
                'tribal_bonus_basis' => 'officer',
                'tribal_bonus_skips_extremes' => false,
                'remarks' => 'The PPT rule set (requirements §5).',
            ]);

            foreach (range(1, 5) as $before) {
                $rule->years()->create([
                    'years_before' => $before,
                    'cr_min_marks' => null,
                    'cr_mandatory' => false,
                    'loyalty_mandatory' => false,
                    'property_mandatory' => false,
                ]);
            }
        }
    }
}
