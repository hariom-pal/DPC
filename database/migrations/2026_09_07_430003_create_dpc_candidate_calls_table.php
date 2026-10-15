<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the committee did with each candidate at the sitting (business instruction,
 * 2026-09-07).
 *
 * > *When all the members click the DPC that is being held, the whole candidate list
 * > should appear. Clicking a candidate shows the full record, and a Call button
 * > appears — the call activates only on the date and time the DPC is scheduled for.
 * > Records should be shown candidate by candidate, and once a candidate's DPC is
 * > done, mark it concluded. How the marking works we will look at later.*
 *
 * ### One row per candidate per sitting, and only once something happens
 *
 * A candidate the committee has not reached yet has no row. That is the difference
 * between *"not called"* and *"called and nothing recorded"*, and a committee minute
 * has to be able to tell them apart.
 *
 * ### Why not a column on `dpc_evaluation_rows`
 *
 * The chart is **projected** from the Seniority List and rebuilt whenever that list
 * is re-uploaded. Anything the committee recorded during a sitting would be
 * destroyed by a re-upload — which is exactly the kind of silent loss the projection
 * already has to be careful about. A sitting is its own event with its own
 * lifetime, so it gets its own table.
 *
 * ### What is deliberately absent
 *
 * **Marks.** Business said the marking scheme is undecided — *"how the marking is to
 * be done we will see later"* — so this records **that** the committee called a
 * candidate and **that** it concluded them, with who and when. Inventing a score
 * column now would be inventing the scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_candidate_calls', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();

            /*
             | One record per candidate per sitting. A second call of the same
             | candidate updates this row rather than adding another — the question
             | the screen asks is "where has the committee got to", not "how many
             | times was somebody called".
             */
            $table->unique(['dpc_proceeding_id', 'dpc_evaluation_row_id'], 'dpc_candidate_calls_unique');

            $table->timestamp('called_at')->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('concluded_at')->nullable();
            $table->foreignId('concluded_by')->nullable()->constrained('users')->nullOnDelete();

            // Whatever the committee wants on the record beside the candidate. Not
            // marks — the scheme is undecided.
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_candidate_calls');
    }
};
