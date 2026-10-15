<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Committee membership becomes a property of the DPC, not of the person**
 * (change register of 2026-08-26, items 7, 8 and 9).
 *
 * The register of 2026-08-24 put the committee's composition in a
 * `commission_members` master and had a committee row *point* at it. This
 * register reverses that, and the reason it gives is the one that matters:
 *
 * > *Committee Members are NOT permanent system roles. Committee Membership is
 * > valid only for that specific DPC. A user may simultaneously be AO, AH and a
 * > Promotion Candidate and also become a Committee Member for a particular DPC.
 * > This should be stored only in the DPC Committee assignment.*
 *
 * So the composition attributes move **onto the assignment**. A person is not
 * permanently "the SC member"; they are the member who represented SC *on this
 * committee, on this DPC*. Storing it on a master made it a standing property of
 * the person, which is exactly what the register says it is not.
 *
 * ### The six attributes, and why they are six independent flags
 *
 * SC, ST, OBC, Female, Bhar Sadhak Sachiv and Vibhag Sachiv. The register is
 * explicit that **one member may satisfy several at once** — its own example is a
 * member who is Female, ST and Bhar Sadhak Sachiv together — so this is not one
 * `category` column with six values. That independence is the whole point: it is
 * what lets a three-member committee satisfy five requirements, which the register
 * asks for by name.
 *
 * **Two of these are new.** `is_vibhag_sachiv` had no equivalent on the master,
 * and `is_bhar` is renamed to `is_bhar_sadhak_sachiv` because that is what the
 * register calls it. Both are kept under the register's own names rather than
 * translated: the composition rules are written in those terms, and renaming them
 * would make the rule and its source stop matching.
 *
 * **These are composition attributes, not reservation attributes.** The register
 * says so in a heading of its own. `ReservationCategory` is about the officers
 * being *promoted* and drives roster points and reserved-seat arithmetic in the
 * scoring engine; these are about who sits at the table. They share three names
 * and mean different things, and nothing here touches the engine.
 *
 * ### Two chairmen, which are not the same chairman
 *
 * The register distinguishes them and the portal now does too:
 *
 *  - **The CGPSC Chairman** holds a seat on every committee (`capacity`
 *    `cgpsc_chairman`), is added by the system, and the Authorized Officer can
 *    neither add nor remove them — item 9.
 *  - **The DPC Chairman** is designated *for this DPC* by the CGPSC Chairman, who
 *    may designate a member or take it themselves. That is `is_dpc_chairman`, a
 *    flag rather than a capacity, precisely so the CGPSC Chairman taking it needs
 *    one row rather than two contradictory ones.
 *
 * `unique(dpc_proposal_id, is_dpc_chairman)` with a nullable flag makes "at most
 * one DPC Chairman per DPC" a fact the database holds rather than a rule the
 * service is trusted to remember.
 *
 * ### Replacement keeps the outgoing member
 *
 * A member may be replaced at any stage. `active_flag` is `1` or NULL — never `0`
 * — so `unique(dpc_proposal_id, user_id, active_flag)` reads as *one serving row
 * per person per DPC, any number of replaced ones*, because NULLs do not collide
 * in MySQL or SQLite. The replaced row keeps its attributes and gains who replaced
 * it, when, and why. **A proceeding that already cites a member stays truthful**,
 * which is the only reason this is a chain rather than an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            // Item 8. Independent flags: one member may satisfy several.
            $table->boolean('is_sc')->default(false)->after('capacity');
            $table->boolean('is_st')->default(false)->after('is_sc');
            $table->boolean('is_obc')->default(false)->after('is_st');
            $table->boolean('is_female')->default(false)->after('is_obc');
            $table->boolean('is_bhar_sadhak_sachiv')->default(false)->after('is_female');
            $table->boolean('is_vibhag_sachiv')->default(false)->after('is_bhar_sadhak_sachiv');

            // Item 9: designated per DPC, by the CGPSC Chairman. Nullable `1`.
            $table->unsignedTinyInteger('is_dpc_chairman')->nullable()->after('is_vibhag_sachiv');

            // Item 9e: replacement history. Nullable `1`, never `0`.
            $table->unsignedTinyInteger('active_flag')->nullable()->after('sort_order');
            $table->timestamp('replaced_at')->nullable()->after('active_flag');
            $table->text('replacement_reason')->nullable()->after('replaced_at');
        });

        // Separate statements: SQLite implements each of these by rebuilding the
        // table, and it refuses a self-referencing foreign key added in the same
        // pass as the column it points at.
        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->foreignId('replaced_by')->nullable()->after('replacement_reason')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            // The row this one replaces — the chain, read backwards.
            $table->foreignId('replaces_id')->nullable()->after('replaced_by')
                ->constrained('dpc_committee_members')->nullOnDelete();
        });

        $this->backfill();

        /*
         | The replacement index is created **before** the incumbent is dropped.
         |
         | `unique(dpc_proposal_id, user_id)` is the only index covering
         | `user_id`'s foreign key, and MySQL refuses to drop an index in that
         | position (errno 1553). Creating the wider one first keeps the column
         | covered throughout.
         */
        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->unique(['dpc_proposal_id', 'user_id', 'active_flag'], 'dpc_committee_members_serving_unique');
            $table->unique(['dpc_proposal_id', 'is_dpc_chairman'], 'dpc_committee_members_dpc_chairman_unique');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropUnique('dpc_committee_members_dpc_proposal_id_user_id_unique');
        });
    }

    public function down(): void
    {
        // The old unique index cannot hold two rows for one person on one DPC, so
        // the replaced rows have to go first — they are exactly the rows it could
        // never have permitted.
        DB::table('dpc_committee_members')->whereNull('active_flag')->delete();

        DB::table('dpc_committee_members')
            ->where('is_dpc_chairman', 1)
            ->update(['capacity' => 'chairman']);

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->unique(['dpc_proposal_id', 'user_id'], 'dpc_committee_members_dpc_proposal_id_user_id_unique');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropUnique('dpc_committee_members_serving_unique');
            $table->dropUnique('dpc_committee_members_dpc_chairman_unique');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_id');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropColumn([
                'is_sc',
                'is_st',
                'is_obc',
                'is_female',
                'is_bhar_sadhak_sachiv',
                'is_vibhag_sachiv',
                'is_dpc_chairman',
                'active_flag',
                'replaced_at',
                'replacement_reason',
            ]);
        });
    }

    /**
     * Give every existing row the serving flag, and re-read the old `chairman`
     * capacity as what it actually meant.
     *
     * **A committee recorded before this register has no CGPSC Chairman seat**, and
     * one is not invented here. Its `chairman` row was the person who *conducted*
     * that DPC — which is the DPC Chairman under the new reading — so it becomes an
     * ordinary member carrying the designation. Writing those rows up to
     * `cgpsc_chairman` instead would put a person on a seat they never held, on a
     * record that may already have been signed.
     */
    private function backfill(): void
    {
        DB::table('dpc_committee_members')->update(['active_flag' => 1]);

        DB::table('dpc_committee_members')
            ->where('capacity', 'chairman')
            ->update(['capacity' => 'member', 'is_dpc_chairman' => 1]);
    }
};
