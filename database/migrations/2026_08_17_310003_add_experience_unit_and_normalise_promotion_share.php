<?php

declare(strict_types=1);

use App\Domain\Master\Enums\ExperienceUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes to how a recruitment rule's feeder conditions are recorded, both
 * driven by the screen being made readable without training.
 *
 * **1. The experience unit becomes explicit.** `min_experience_years` displayed
 * as a bare "5" and the user had to know what it counted. A unit column lets the
 * form show "5 Years" and lets a rule state a qualifying period in months, which
 * some Schedule-IV entries do. Every existing row is years, so that is the
 * default and the backfill is exact — no value changes meaning.
 *
 * **2. `promotion_percentage` is normalised to a share of the promotion quota.**
 * This is a semantic correction, and the reason the migration is not trivial.
 *
 * The column was being written two different ways:
 *
 *  - as a share of *all* posts, mirroring the rule's own
 *    `promotion_recruitment_pct` — the Lecturer rule had two feeders at 25 each
 *    against a rule promotion quota of 50; and
 *  - as a share of the *promotion quota*, which is what the field is now defined
 *    to mean and what the screen now validates: the feeder shares must total
 *    exactly 100.
 *
 * Under the first reading "25" means "a quarter of all Lecturer posts"; under the
 * second it means "a quarter of the posts filled by promotion". Only the second
 * can be validated, because only the second has a fixed total.
 *
 * The rescale is `share / sum(shares) * 100` per rule, which **preserves the
 * ratio between feeders exactly** — the only information the old values actually
 * carried. Lecturer's 25/25 becomes 50/50 (still one-to-one); Principal's sole
 * feeder at 75 becomes 100 (still the only source). Rules already totalling 100
 * are untouched. The rule's own `promotion_recruitment_pct` is **not** modified,
 * so how much of the cadre is filled by promotion is unchanged — this only
 * changes how that quota is described as being divided up.
 *
 * Nothing outside the Master module and the Pre-DPC projection reads either
 * column: no eligibility, scoring, proceeding or DPC code path. Verified by
 * grep before this was written, which is why a semantic change here cannot move
 * a calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_rule_feeders', function (Blueprint $table) {
            $table->string('min_experience_unit', 10)
                ->default(ExperienceUnit::Years->value)
                ->after('min_experience_years');
        });

        // Every pre-existing row counted years. Stated explicitly rather than
        // relying on the column default, so the intent survives a future default
        // change.
        DB::table('recruitment_rule_feeders')->update(['min_experience_unit' => ExperienceUnit::Years->value]);

        $this->normalisePromotionShares();
    }

    public function down(): void
    {
        // The rescale is deliberately not reversed. The ratio between feeders is
        // preserved by it, so the old absolute numbers carry no information the
        // new ones do not — and reversing would need the original sum, which is
        // no longer recorded anywhere. Dropping the column is reversible; the
        // arithmetic is not, and pretending otherwise would be worse.
        Schema::table('recruitment_rule_feeders', function (Blueprint $table) {
            $table->dropColumn('min_experience_unit');
        });
    }

    /**
     * Rescale each rule's feeder shares so they total exactly 100.
     */
    private function normalisePromotionShares(): void
    {
        $totals = DB::table('recruitment_rule_feeders')
            ->select('recruitment_rule_id', DB::raw('SUM(promotion_percentage) AS total'))
            ->groupBy('recruitment_rule_id')
            ->pluck('total', 'recruitment_rule_id');

        foreach ($totals as $ruleId => $total) {
            $total = (float) $total;

            // Already correct, or nothing to scale from — a rule whose feeders
            // all sit at 0 has no ratio to preserve and is left for an operator
            // to fill in, rather than having 100 invented for it.
            if ($total <= 0.0 || abs($total - 100.0) < 0.01) {
                continue;
            }

            $feeders = DB::table('recruitment_rule_feeders')
                ->where('recruitment_rule_id', $ruleId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'promotion_percentage']);

            // Scale all but the last, then give the last the remainder, so the
            // total is exactly 100 and rounding cannot leave 99.99.
            $running = 0.0;
            $last = $feeders->count() - 1;

            foreach ($feeders as $index => $feeder) {
                $share = $index === $last
                    ? round(100.0 - $running, 2)
                    : round((float) $feeder->promotion_percentage / $total * 100, 2);

                $running += $share;

                DB::table('recruitment_rule_feeders')
                    ->where('id', $feeder->id)
                    ->update(['promotion_percentage' => $share]);
            }
        }
    }
};
