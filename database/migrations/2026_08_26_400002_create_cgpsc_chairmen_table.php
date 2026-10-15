<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Who the CGPSC Chairman is, as an administered and dated record**
 * (change register of 2026-08-26, item 9, as clarified).
 *
 * Item 9 requires every DPC to contain the CGPSC Chairman, added by the system
 * rather than by the Authorized Officer. That makes "who is the CGPSC Chairman"
 * a question the portal has to be able to answer at composition time — and until
 * now it could only answer it as *"whoever happens to hold the `chairman` role"*,
 * which is not an answer:
 *
 *  - nothing stopped two accounts holding the role at once, and a committee
 *    cannot have two CGPSC Chairmen;
 *  - the role grant carries no tenure, so a DPC conducted last year could not say
 *    who was Chairman **then** — only who is Chairman now.
 *
 * The second is the one that decides the shape. A Chairman succeeded in March
 * must not retrospectively appear on February's committee, so this is an
 * **effective-dated chain** rather than a flag on a user: appointing a successor
 * closes the incumbent's tenure and opens a new row, and every DPC keeps pointing
 * at the row that was serving when its committee was composed.
 *
 * **`active_flag` is `1` or NULL, never `0`** — the same pattern as
 * `user_department_assignments`, `pre_dpc_proposals` and `department_posts`. A
 * `unique(active_flag)` therefore reads as *at most one serving Chairman in the
 * portal, any number of former ones*, because NULLs do not collide. Storing `0`
 * would make the second former Chairman a constraint violation.
 *
 * **The contact details are copied, not joined.** `name`, `designation`, `email`
 * and `mobile` are held here rather than read live off the user, for the reason
 * every other signed record in this portal copies what it states: a committee
 * records who sat on it, and editing an account two years later must not rewrite
 * a document that has already been issued. `user_id` is what signs in and
 * conducts; these columns are what the record says.
 *
 * The register asks for this to be maintained by the Administrator **with an
 * audit trail**, which the model gets from `Auditable` and the blameable columns
 * below — the same treatment every other master in this application has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cgpsc_chairmen', function (Blueprint $table): void {
            $table->id();

            // The public identifier (AD-8) — never a sequential id in a URL.
            $table->uuid()->unique();

            /*
             | The account that signs in, nominates a DPC Chairman and conducts.
             |
             | Nullable so that a Chairman's *tenure* can be recorded before their
             | login exists, and `nullOnDelete` so removing an account does not
             | erase the historical fact that they held the office. Not unique: a
             | Chairman may serve, be succeeded, and be appointed again.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // The snapshot. See the class docblock for why these are not a join.
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Nullable on purpose. See the class docblock.
            $table->unsignedTinyInteger('active_flag')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // At most one serving Chairman, enforced by the database rather than
            // by every future caller remembering to check.
            $table->unique('active_flag', 'cgpsc_chairmen_serving_unique');

            // "Who was Chairman on this date" — the query a historical committee
            // and every proceeding document makes.
            $table->index(['effective_from', 'effective_to'], 'cgpsc_chairmen_tenure_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cgpsc_chairmen');
    }
};
