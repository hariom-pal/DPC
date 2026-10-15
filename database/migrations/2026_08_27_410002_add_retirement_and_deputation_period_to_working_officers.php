<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Working Officer List has to carry for the retirement and deputation
 * vacancies to be calculable (change register of 2026-08-27, items 3 and 4).
 *
 * > *If an officer occupying the Promotion Post retires between 1 January and
 * > 30 November of the DPC Year, automatically increase one additional vacancy.*
 *
 * > *If an officer occupying the Promotion Post remains on deputation during the
 * > same vacancy period, automatically increase one additional vacancy.*
 *
 * Neither was answerable from the data. The chart carried no retirement date at
 * all, and it carried a single `deputation_date` — one date cannot express a
 * *period*, and the register's test is whether the deputation **overlaps** the
 * vacancy period. A single date can only say when something began.
 *
 * ### `deputation_date` becomes `deputation_from`
 *
 * Renamed rather than joined by a second column, because the existing value *is*
 * the start of the period — that is the only thing a lone date on a deputation
 * record can mean. `deputation_to` is added beside it and left null for every
 * existing row, which reads as **open-ended**: an officer recorded as having gone
 * on deputation with no return date is still on it, and that is precisely the
 * case that adds a vacancy.
 *
 * ### Why the retirement date is captured and not derived
 *
 * A superannuation age plus a date of birth would give a retirement date without
 * a new column, and would be inventing a business rule: the age differs by
 * service and by cadre, and the register states none. The date is recorded, and
 * the Excel template carries a column for it.
 *
 * **Nullable, and its absence is not a defect.** Most officers on a chart are not
 * retiring in the DPC Year, and requiring a date for all of them would make the
 * upload harder for no gain. A row with no retirement date simply contributes no
 * retirement vacancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            /*
             | The date this officer superannuates.
             |
             | Recorded rather than derived — see the class docblock. Placed after
             | `date_of_posting_on_promotion_from_post` because that is the group a
             | data-entry operator reads it with; the column was
             | `date_of_posting_on_post` until migration `360002` renamed the post
             | pair, which is why the obvious `after()` target does not exist.
             */
            $table->date('date_of_retirement')->nullable()->after('date_of_posting_on_promotion_from_post');
        });

        /*
         | Separate statements: SQLite implements a rename by rebuilding the table,
         | and it will not accept an added column in the same pass.
         */
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->renameColumn('deputation_date', 'deputation_from');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            // Null means "still on deputation", which is the case that counts.
            $table->date('deputation_to')->nullable()->after('deputation_from');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropColumn('deputation_to');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->renameColumn('deputation_from', 'deputation_date');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropColumn('date_of_retirement');
        });
    }
};
