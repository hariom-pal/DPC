<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Currently Working chart becomes a chart of **posts**, not of people
 * (business directive, 2026-09-02).
 *
 * Until now the chart listed only officers, and the clear vacancy was inferred:
 * `Sanctioned − Working`. The officer then had to re-state that number a second
 * time, by hand, in a Vacancy Breakdown grid whose rows had to add back up to
 * the figure the engine had already worked out. Two sources for one truth, and
 * the grid was where a demo stalled: it is refused until the rows balance, and
 * nothing in the upload fills it.
 *
 * So a row may now be a **vacant post** instead of an officer. `status` says
 * which, the chart accounts for every sanctioned post explicitly, and the
 * breakdown is derived from it rather than typed beside it.
 *
 * **`name` and `dob` become nullable.** A vacant post has neither, and inventing
 * a placeholder name for an empty chair would put fiction into a table this
 * portal has to be able to produce in court. They stay `required` for a row
 * whose status is `working` — enforced in `DpcRowRules::workingOfficer()`, which
 * is where the rule is legible, not in a column definition.
 *
 * Existing rows are back-filled to `working`, which is what every one of them is:
 * before this migration a row could only ever have been an officer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->string('status', 10)
                ->default('working')
                ->after('sn')
                ->comment('working | vacant');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->date('dob')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A vacant row has no name and no date of birth, so it cannot survive
        // columns that forbid both. Drop those rows before restoring the
        // constraints, or the reversal fails on the data it created.
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
