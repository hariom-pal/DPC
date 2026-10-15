<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **The Commission Members master is withdrawn** (change register of 2026-08-26,
 * item 5).
 *
 * > *Remove the separate Commission Member Master. Members should not be
 * > maintained as master data. Members will only be selected or created during
 * > DPC creation.*
 *
 * This reverses item 13 of the register of 2026-08-24, which created the master
 * two days earlier. The reversal is not a correction of a mistake so much as a
 * change of reading: the earlier register asked for members to be *selectable by
 * the categories they represent*, and a master was the obvious way to hold those
 * categories. This register says the categories belong to the **assignment** —
 * a person represents SC *on this committee*, not permanently — and once that is
 * true the master has nothing left to hold. Migration `400001` moved the
 * attributes across; this one removes what they were moved from.
 *
 * **`Role::Member` is untouched, and the distinction is worth stating** because
 * the two things are easily confused. "Member, CGPSC" is a *login role* held by a
 * Commission officer who receives allotted DPCs and conducts proceedings and
 * circulations. The master removed here was a *list of candidate committee
 * members*. Item 7 forbids committee membership from becoming a permanent role;
 * it does not abolish the Commission officer, and nothing in this register touches
 * allotment, proceedings or circulation authority beyond moving who allots.
 *
 * ### Why the two reference columns go rather than being left nullable
 *
 * `dpc_committee_members.commission_member_id` and
 * `dpc_allotments.commission_member_id` both point at a table that will not exist.
 * Leaving them as orphaned nullable integers is the option that looks safest and
 * is not: a column that references nothing, on two tables that matter, is a
 * column the next migration reconnects to something else. Neither carries
 * information that is lost — the committee row holds its own name, designation,
 * mobile and e-mail snapshot, and the allotment holds `allotted_to`, which is who
 * actually conducted the DPC and is what every policy already reads.
 *
 * ### Reversibility
 *
 * `down()` rebuilds the table and both columns, so the schema round-trips. It does
 * **not** repopulate the master, and cannot: the rows were a list of people, and
 * reconstructing them from committee assignments would invent standing
 * ("represents SC permanently") out of a per-DPC fact. A migration that restores a
 * shape honestly and says it cannot restore the data is better than one that
 * fabricates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_member_id');
        });

        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_member_id');
        });

        Schema::dropIfExists('commission_members');
    }

    public function down(): void
    {
        Schema::create('commission_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->string('post_title')->nullable();
            $table->boolean('is_sc')->default(false);
            $table->boolean('is_st')->default(false);
            $table->boolean('is_obc')->default(false);
            $table->boolean('is_female')->default(false);
            $table->boolean('is_bhar')->default(false);
            $table->string('section')->nullable();
            $table->boolean('chairman_eligible')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'chairman_eligible'], 'commission_members_status_chairman_index');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->foreignId('commission_member_id')->nullable()->after('user_id')
                ->constrained('commission_members')->nullOnDelete();
        });

        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->foreignId('commission_member_id')->nullable()->after('allotted_to')
                ->constrained('commission_members')->nullOnDelete();
        });
    }
};
