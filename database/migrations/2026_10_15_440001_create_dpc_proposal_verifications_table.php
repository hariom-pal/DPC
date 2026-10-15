<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Rule 1.1 pre-submission confirmations (DPC Understanding Document, rule 1.1).
 *
 * > *Before a DPC is started, the department confirms that the vacancy setup has been
 * > matched with the setup sanction order, the Recruitment Rules and the gradation
 * > list, and that the count of filled posts has been matched with the minutes of the
 * > previous DPC.*
 *
 * ### Append-only, one row per statement
 *
 * A confirmation and a withdrawal are both rows. Nothing here is updated in place, so
 * the table *is* the verification history: who confirmed what, against which revision
 * of the proposal, on what basis, and when — and who later withdrew it. The current
 * state of an item is its latest row for the proposal's current revision.
 *
 * ### Tied to the revision
 *
 * `revision_number` is the proposal's revision when the statement was made. A return
 * opens a new revision, and a confirmation made for the revision that was returned is
 * not carried forward — what the department verifies is the version it submits.
 *
 * ### `basis`
 *
 * The figures the officer was looking at when confirming (sanctioned strength, the
 * recruitment rule version, the seniority list's fingerprint, the working strength).
 * `basis_hash` is compared with the live figures at the submission gate, so a
 * confirmation given before the data changed is reported as needing re-confirmation
 * rather than silently standing for data nobody verified.
 *
 * Additive only: no existing table or row is touched. Proposals that predate this
 * table simply have no rows, which is shown as "not recorded" — never as verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_proposal_verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');

            // sanctioned_strength | recruitment_rule | seniority_list | previous_dpc_reconciliation
            $table->string('item', 40);

            // confirmed | withdrawn
            $table->string('status', 20);

            $table->text('remarks')->nullable();

            $table->json('basis')->nullable();
            $table->char('basis_hash', 64)->nullable();

            /*
             | Previous DPC reference — only on `previous_dpc_reconciliation`.
             |
             | `in_system`: a previous DPC held through this portal, by its proposal.
             | `offline`:   a previous DPC held outside the portal — its reference,
             |              the date it met, and the minutes as a PDF.
             | `none`:      the department states that no DPC has been held for this
             |              post before; remarks explain it.
             */
            $table->string('previous_dpc_basis', 20)->nullable();
            $table->foreignId('previous_dpc_proposal_id')->nullable()
                ->constrained('dpc_proposals')->nullOnDelete();
            $table->string('previous_dpc_reference', 120)->nullable();
            $table->date('previous_dpc_held_on')->nullable();

            // The offline minutes, with the same discipline as record-gap evidence.
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'revision_number', 'item'], 'dpc_prop_verif_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proposal_verifications');
    }
};
