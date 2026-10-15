<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complete vacancy calculation, stored for audit (change register of
 * 2026-08-27, items 8 and 10).
 *
 * > *Store complete vacancy calculation for audit.*
 *
 * ### Why this is stored when the old total was derived
 *
 * `VacancyCalculationService` computed `sanctioned − working` on read and
 * persisted nothing, deliberately: both numbers were on the proposal, so storing
 * the difference would have created a second version of a truth a later edit could
 * leave stale.
 *
 * **That reasoning does not survive this register.** The calculation now depends on
 * things that are *not* on the proposal and that change underneath it:
 *
 *  - the **sanctioned strength**, which lives on the Department Post Mapping and is
 *    superseded by Government Order;
 *  - the **promotion percentage**, which lives on the Recruitment Rule and is
 *    versioned;
 *  - each officer's **retirement date** and **deputation period**, and the vacancy
 *    period they are tested against.
 *
 * A figure derived from five moving sources cannot be recomputed later and be
 * guaranteed to give the answer the Commission acted on. So the calculation is
 * recorded — every input and every output — and the screens read the record rather
 * than recomputing.
 *
 * ### Append-only, like every other calculation in this portal
 *
 * Recalculating writes a new row; `superseded_by_id` chains them. This is the same
 * doctrine as `dpc_calculation_runs`: the Commission acts on a calculation, and if
 * a promotion is contested the question is what the engine produced *at that
 * moment under the sources in force then*. A row that could be edited could not
 * answer it.
 *
 * `inputs` and `contributors` are JSON because they are evidence rather than
 * operands: nothing queries inside them, and their shape follows the register's
 * summary rather than a schema this table should be asked to enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_vacancy_calculations', function (Blueprint $table): void {
            $table->id();

            // The public identifier (AD-8) — never a sequential id in a URL.
            $table->uuid()->unique();

            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            /*
             | ---- The inputs, as they stood -------------------------------------
             |
             | Copied rather than referenced. The mapping and the rule are the live
             | sources; these columns are what they said when the calculation ran,
             | which is the whole point of storing it.
             */
            $table->foreignId('sanctioned_from_post_id')->nullable()
                ->constrained('department_posts')->nullOnDelete();
            $table->unsignedInteger('sanctioned_strength');

            $table->foreignId('recruitment_rule_id')->nullable()
                ->constrained('recruitment_rules')->nullOnDelete();

            // The promotion share as the rule stated it. `decimal` and not float:
            // a percentage that decides how many people are promoted is not a
            // number to hold approximately.
            $table->decimal('promotion_percentage', 5, 2);

            // The window the retirement and deputation tests were applied over.
            $table->date('period_from');
            $table->date('period_to');

            /*
             | ---- The outputs ---------------------------------------------------
             |
             | Every figure the register's summary names, in its order. Signed,
             | because a working strength above the sanctioned strength is a data
             | fault the screen must be able to *state* — storing it as unsigned
             | would turn a reportable error into an impossible row.
             */
            $table->integer('working_strength');
            $table->integer('base_vacancy');
            $table->unsignedInteger('retirement_vacancy');
            $table->unsignedInteger('deputation_vacancy');
            $table->integer('total_vacancy');

            // The exact figure beside the integer it was rounded to, so a reader
            // can see what the rounding rule did. See BD-33.
            $table->decimal('promotion_vacancy_exact', 8, 2);
            $table->integer('promotion_vacancy');
            $table->integer('direct_recruitment_vacancy');

            // Which rounding rule produced the integer above, recorded so a
            // calculation made before the rule is settled stays explainable.
            $table->string('rounding_rule', 20);

            /*
             | Which officers contributed a retirement or a deputation vacancy, and
             | why. Without this the two counts are assertions; with it they are
             | answerable to the officer they were counted against.
             */
            $table->json('contributors');

            // Everything else a reader needs to reconstruct the run without
            // reading five other tables.
            $table->json('inputs');

            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at');

            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('dpc_vacancy_calculations')->nullOnDelete();

            $table->timestamps();

            // "The live calculation for this proposal" — the query every screen
            // makes, and the only one that runs per page load.
            $table->index(['dpc_proposal_id', 'superseded_by_id'], 'dpc_vacancy_calc_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_vacancy_calculations');
    }
};
