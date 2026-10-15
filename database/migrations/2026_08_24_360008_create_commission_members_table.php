<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The **Commission Members Master** (change item 13), and the nomination of a
 * **DPC Chairman** from it (change item 14).
 *
 * **Why a master and not a query over `users`.** The Commission's members are
 * selected onto a DPC committee by the *categories they represent* — SC, ST, OBC,
 * Female, Bhar, and the Vibhag they come from — and none of that is a property of
 * a login. Until now the committee screen offered "every user holding the Chairman
 * or Member role", which cannot express "this committee needs an SC member and a
 * woman on it" and so left the composition rules to be satisfied by hand.
 *
 * **The five category flags are independent booleans, deliberately.** The register
 * is explicit that *a single member may satisfy multiple categories* — its example
 * is a member who is SC, Female and Bhar at once — so this is not one
 * `category` column with five values. That independence is also what makes item
 * 15's selection meaningful: one member covering three requirements is what allows
 * a three-member committee to satisfy six.
 *
 * `user_id` is nullable and unique. Nullable because a member may be registered
 * before their login is provisioned; unique because two member records pointing at
 * one account would let the same person be counted twice toward a quorum.
 *
 * `chairman_eligible` is what item 14's nomination draws from: every DPC starts
 * with the CGPSC Chairman, who later nominates one member to conduct it. That
 * nomination is recorded on `dpc_allotments` — the table that already records the
 * Chairman handing a DPC to a Member (`allotted_to`) — which gains a reference to
 * the member record, so the nomination names a *Commission Member* and not merely
 * a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_members', function (Blueprint $table): void {
            $table->id();

            // The public identifier (AD-8). Every aggregate in this application is
            // addressed by UUID and never by a sequential id.
            $table->uuid()->unique();

            $table->string('name');

            // The account this member signs in with, once they have one.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // The member's own department and post — which is what item 16's
            // level validation compares against the promotion post.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('department_posts')->nullOnDelete();

            // Kept beside `post_id` as free text for a member whose post is not in
            // any department's establishment — a retired officer or an outside
            // expert. Read through the model's accessor, never raw.
            $table->string('post_title')->nullable();

            /*
             | The categories a member represents (item 13). Independent flags: one
             | member may be SC *and* Female *and* Bhar, and the register's example
             | is exactly that.
             |
             | "Bhar" is the deck's term for a member drawn from outside the
             | Commission's own cadre; it is kept under the deck's own name rather
             | than translated, because that is what the composition rules call it.
             */
            $table->boolean('is_sc')->default(false);
            $table->boolean('is_st')->default(false);
            $table->boolean('is_obc')->default(false);
            $table->boolean('is_female')->default(false);
            $table->boolean('is_bhar')->default(false);

            // Vibhag / Section.
            $table->string('section')->nullable();

            $table->boolean('chairman_eligible')->default(false);

            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // "Which active members are chairman-eligible" is the query the
            // nomination screen makes, and "which active members represent SC" is
            // the one the selection optimiser makes.
            $table->index(['status', 'chairman_eligible'], 'commission_members_status_chairman_index');
        });

        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            /*
             | Which Commission Member a committee row *is* (change items 13-16).
             |
             | The row keeps its own `name`/`designation`/`mobile`/`email` snapshot —
             | the historical committee for a proposal must not silently change when
             | a member's record is edited later — and this reference is what the
             | category and level checks read, because those are questions about the
             | member's standing rather than about the snapshot.
             |
             | Nullable, for two reasons that both stay true: committees recorded
             | before this ruling name a user and no member, and an external member
             | (where the Committee Configuration permits one) has no member record
             | at all.
             */
            $table->foreignId('commission_member_id')->nullable()->after('user_id')
                ->constrained('commission_members')->nullOnDelete();
        });

        Schema::table('dpc_allotments', function (Blueprint $table): void {
            /*
             | Item 14: the nomination names a Commission Member.
             |
             | Nullable, because allotments recorded before this ruling named a user
             | and no member record. `SET NULL` rather than `RESTRICT`: withdrawing a
             | member from the master must not be blocked by a historical
             | nomination, and the allotment keeps `allotted_to` — who actually
             | conducted the DPC is on the row regardless.
             */
            $table->foreignId('commission_member_id')->nullable()->after('allotted_to')
                ->constrained('commission_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_committee_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_member_id');
        });

        Schema::table('dpc_allotments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_member_id');
        });

        Schema::dropIfExists('commission_members');
    }
};
