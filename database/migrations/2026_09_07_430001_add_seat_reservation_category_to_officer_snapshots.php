<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category of the **seat** an officer occupies, beside the category the officer
 * belongs to (business instruction, 2026-09-07).
 *
 * > *I want one more column in the working list — Original Category, and which
 * > category's seat he is on for the post he holds now. Suppose his category is SC
 * > but he came in on a General seat. Put it in the seniority list too; we will look
 * > at how to use it later.*
 *
 * ### Why a second column rather than a second reading of the first
 *
 * `reservation_category_id` has carried both meanings until now, and they agreed as
 * long as nobody was appointed against a point of another category. Two consumers
 * read it, and they want opposite halves:
 *
 * | Reader | Wants |
 * |---|---|
 * | `DpcCalculationService` | the officer's **own** category — it is what puts them on the SC or ST waiting list |
 * | `VacancyEngine::classification()` | the **seat's** category — it is what the roster point is |
 *
 * Business settled which half the existing column keeps: `CAT` is the officer's own.
 * So this is the seat, and nothing that reads `reservation_category_id` today
 * changes behaviour.
 *
 * ### Nullable, and it stays nullable
 *
 * Every snapshot already written predates this column, and a department that does
 * not maintain roster points has nothing to put in it. Null means *"not recorded"*
 * — which is different from, and must not be confused with, an officer sitting on a
 * point of their own category.
 *
 * ### On all three snapshots, not only the two named
 *
 * `dpc_evaluation_rows` is *projected* from `dpc_seniority_entries`. A column that
 * existed on the source and not on the projection would be recorded by the
 * department, carried as far as the seniority list, and then silently dropped on the
 * screen the committee actually sits with. That is not a smaller change than adding
 * it — it is the same change with a hole in the middle.
 *
 * Additive only, per the change-control rule: a nullable column and a foreign key,
 * no column altered, none dropped.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'dpc_working_officers',
        'dpc_seniority_entries',
        'dpc_evaluation_rows',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('seat_reservation_category_id')
                    ->nullable()
                    ->after('reservation_category_id')
                    ->constrained('reservation_categories')
                    // `restrictOnDelete`, matching `reservation_category_id`: a
                    // category cited by a concluded DPC is withdrawn by status, never
                    // deleted, and a cascade here would rewrite a signed record.
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('seat_reservation_category_id');
            });
        }
    }
};
