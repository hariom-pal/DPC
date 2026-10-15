<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute tribal-area service to the CR year it was served in (change register of
 * 2026-08-27, item 18).
 *
 * > *Tribal Area benefit shall always be linked with the same CR year. No Tribal
 * > benefit shall be carried forward to another year.*
 *
 * ### What this migration does, and what it deliberately does not
 *
 * `dpc_evaluation_rows.tribal_area_service_years` is a single number for the whole
 * officer, so the chart could say *how much* tribal service there was and never
 * *when*. That is the gap item 18 names, and this closes it: the flag now sits on
 * the CR year, so the benefit is attributable and cannot be read as applying to a
 * year it was not served in.
 *
 * **It does not change how the bonus is scored, and that is a decision the register
 * forces.** `ScoringService` implements PPT §5.2 — *"+1 for having served in a
 * tribal area at all, not one per year"* — and item 21 says in as many words:
 * *"Implement the CR marking system exactly as defined in the current approved PPT.
 * Do NOT modify the marking formula."* Turning the flat bonus into a per-year one
 * would modify it.
 *
 * So the two items pull against each other, and this migration takes the reading
 * that satisfies both: **item 18 as a linkage requirement, item 21 as the formula.**
 * The data now records which year, the formula still adds one bonus, and whether the
 * bonus should become per-year is **BD-36** — recorded rather than decided, because
 * deciding it here would either breach item 21 or quietly ignore item 18.
 *
 * `tribal_area_service_years` is **kept**. It is what the S18 chart carries, what
 * the import template writes, and what the current formula reads; removing it would
 * change the score. It becomes the officer-level total beside the per-year
 * attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            /*
             | Whether this officer served in a tribal area during *this* CR year.
             |
             | Nullable rather than defaulting false, so "not recorded" and "recorded
             | as no" stay different facts. A chart filled in before this migration
             | has null throughout, and null is honest: the officer-level total may
             | say there was tribal service without saying which year it fell in, and
             | inventing a year would be inventing the attribution item 18 asks for.
             */
            $table->boolean('tribal_area')->nullable()->after('grade');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->dropColumn('tribal_area');
        });
    }
};
