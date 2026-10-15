<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Department Post Mapping becomes an **effective-dated record authorised by a
 * Government Order**.
 *
 * A mapping — Department + Post + Pay Scale + Class + Promotion Duration — is not
 * an administrative convenience; it is what a Government Order says. When the
 * Government issues a new order the mapping changes, and both facts have to remain
 * true afterwards:
 *
 *   - a DPC conducted last year was conducted under the **old** mapping, and must
 *     still be auditable against the order that authorised it; and
 *   - a DPC raised today must use the **new** one.
 *
 * A row that is edited in place can only satisfy the second. So a new order
 * produces a **new row**, and the previous row is closed rather than changed:
 * `effective_to` is set, `active_flag` becomes NULL, and every proposal that
 * referenced it still points at exactly the mapping it ran under.
 *
 * **`active_flag` is `1` or NULL, never `0`** — the same pattern as
 * `user_department_assignments` and `pre_dpc_proposals`. A unique index that
 * includes it reads as *at most one active mapping per department and post, any
 * number of superseded ones*, because NULLs do not collide in MySQL or SQLite.
 * Storing `0` would make the second superseded row a constraint violation.
 *
 * **The Government Order columns are nullable, and that is deliberate.** Mappings
 * registered before this migration have no order recorded against them, and
 * inventing one would put a false citation on a legal reference. They are backfilled
 * as "not recorded", which is true, and the screen says so.
 *
 * The PDF itself is **not** a column here. It lives in `department_post_documents`,
 * because a mapping may have its scan replaced — a bad scan, a clearer copy — and
 * the register's rule is that the previous file is kept rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_posts', function (Blueprint $table): void {
            $table->string('government_order_number', 120)->nullable()->after('promotion_duration_years');
            $table->date('government_order_date')->nullable()->after('government_order_number');
            $table->date('effective_from')->nullable()->after('government_order_date');
            $table->date('effective_to')->nullable()->after('effective_from');

            // Nullable on purpose. See the class docblock.
            $table->unsignedTinyInteger('active_flag')->nullable()->after('effective_to');
        });

        $this->backfill();

        /*
         | The replacement index is created before the incumbent is dropped.
         |
         | `unique(department_id, name)` covers the same leading columns as the new
         | index, and MySQL refuses to drop an index that is the only one covering a
         | foreign key's columns (errno 1553). Creating the wider index first means
         | the column is always covered.
         */
        Schema::table('department_posts', function (Blueprint $table): void {
            $table->unique(['department_id', 'name', 'active_flag'], 'department_posts_active_mapping_unique');
        });

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropUnique('department_posts_department_id_name_unique');
        });
    }

    public function down(): void
    {
        // A department cannot hold two rows for one post name once the flag is gone,
        // so the superseded versions have to go first — they are the rows the old
        // unique index could never have permitted.
        DB::table('department_posts')->whereNull('active_flag')->whereNotNull('effective_to')->delete();

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->unique(['department_id', 'name'], 'department_posts_department_id_name_unique');
        });

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropUnique('department_posts_active_mapping_unique');
        });

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropColumn([
                'government_order_number',
                'government_order_date',
                'effective_from',
                'effective_to',
                'active_flag',
            ]);
        });
    }

    /**
     * Give every existing mapping a start date and, if it is active, the flag.
     *
     * `effective_from` falls back to the row's own creation date — the first date
     * the portal can honestly say the mapping existed from. The Government Order
     * columns stay null: a mapping registered before orders were recorded has no
     * order, and writing a placeholder would be a false citation on the document
     * that is supposed to be the legal reference.
     */
    private function backfill(): void
    {
        DB::table('department_posts')
            ->whereNull('effective_from')
            ->update([
                'effective_from' => DB::raw('DATE(created_at)'),
            ]);

        DB::table('department_posts')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->update(['active_flag' => 1]);
    }
};
