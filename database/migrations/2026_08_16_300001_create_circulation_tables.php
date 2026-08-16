<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circulation DPC — the note and its items (PPT S45, S47; Conventional deck
 * C3/C7) — M9 Phase 1.
 *
 * **`dpc_circulations`** is the परिभ्रमण टीप: a department's request that the
 * Commission consent to a promotion it reserved, once the missing record has
 * been supplied.
 *
 * **It is its own aggregate, and that is a ruling rather than a preference
 * (AD-24).** It does not reuse, extend or specialize `dpc_proposals`, and no
 * row of type `circulation` is ever created there. A proposal must satisfy
 * M4's submission gate — S14 through S18 complete — which a Circulation can
 * never do, because everything those sections would hold was decided at the
 * original sitting. The two escapes from that were copying the origin's chart,
 * which makes it a Review in all but name, and bypassing the gate, which is
 * precisely TD-23.
 *
 * **`origin_dpc_proposal_id` is referenced and never written.** `dpc_proposals`
 * is frozen M4; the circulation points at it and stops there.
 *
 * **`dpc_circulation_items`** carries one row per held officer, and is where
 * the department's resolution evidence lives — never on the frozen chart. Each
 * item points at the `dpc_proceeding_holds` row it resolves, with
 * `restrictOnDelete` because a hold is a permanent record: it is the reason a
 * promotion did not take effect, and an item must not be able to take one with
 * it.
 *
 * **Nothing here releases a hold.** `hold_released_at` is written in Phase 2,
 * at signature and not before (AQ-17), mirroring the hold's own `released_at`
 * so an item stays readable on its own.
 *
 * **Foreign-key constraint names are given explicitly and every one is inside
 * MySQL's 64-character limit.** M8 shipped a 65-character generated name that
 * meant `dpc_document_review_references` never existed in the real database
 * while 83 SQLite tests stayed green (TD-24). The longest name below is
 * `fk_dpc_circ_item_hold`.
 *
 * `dpc_circulation_documents` is **not** created here. It belongs to Phase 3,
 * which is the phase that renders, signs and publishes the note — the standing
 * project rule that a phase declares only what it implements, so nothing reads
 * as supported behaviour before it is.
 *
 * Additive only. No M4, M5, M6, M7 or M8 table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_circulations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('department_id')
                ->constrained('departments', 'id', 'fk_dpc_circ_department')
                ->cascadeOnDelete();

            // The DPC that held the officers. Fresh **or** Review: S44 shows a
            // Review result carrying `ST SS CIR`, so a Review holds officers
            // exactly as a Fresh DPC does. S47's field label reads "Fresh DPC
            // date", which is narrower than the rule — a Category-B
            // documentation difference, recorded and not implemented.
            $table->foreignId('origin_dpc_proposal_id')
                ->constrained('dpc_proposals', 'id', 'fk_dpc_circ_origin_proposal');
            $table->foreignId('origin_dpc_proceeding_id')
                ->constrained('dpc_proceedings', 'id', 'fk_dpc_circ_origin_proceeding');

            // Snapshots of what S47 displays, so the note stays readable
            // without re-reading the origin (AD-5 applied to the DPC itself).
            $table->string('origin_dpc_code')->nullable();
            $table->date('origin_dpc_date')->nullable();

            // The note's own identifier, minted at department approval. It is
            // deliberately **not** a `dpc_code`: a Circulation is not a DPC and
            // must not appear in any record to have been one (AD-24).
            $table->string('circulation_code')->nullable()->unique();

            // S47 — परिभ्रमण पदोन्नति प्रस्ताव तिथि.
            $table->date('proposal_date');

            $table->string('status', 20)->default('draft');

            $table->foreignId('raised_by')->nullable()
                ->constrained('users', 'id', 'fk_dpc_circ_raised_by')->nullOnDelete();
            $table->string('raised_by_name')->nullable();
            $table->timestamp('raised_at')->nullable();

            $table->foreignId('approved_by')->nullable()
                ->constrained('users', 'id', 'fk_dpc_circ_approved_by')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approval_signature_id')->nullable()
                ->constrained('signatures', 'id', 'fk_dpc_circ_appr_signature')
                ->nullOnDelete();

            $table->timestamp('returned_at')->nullable();
            $table->text('return_remarks')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Phase 2 — the Commission's side. Declared with the table because
            // a nullable column asserts no behaviour; the transitions that
            // fill them are not declared until the phase that performs them.
            $table->foreignId('consented_by')->nullable()
                ->constrained('users', 'id', 'fk_dpc_circ_consented_by')->nullOnDelete();
            $table->string('consented_by_name')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->foreignId('signature_id')->nullable()
                ->constrained('signatures', 'id', 'fk_dpc_circ_signature')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'status'], 'dpc_circ_department_status_index');
            $table->index('origin_dpc_proposal_id', 'dpc_circ_origin_proposal_index');
        });

        Schema::create('dpc_circulation_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_circulation_id')
                ->constrained('dpc_circulations', 'id', 'fk_dpc_circ_item_circulation')
                ->cascadeOnDelete();

            // Restrict, not cascade. A hold is a permanent, append-only record
            // (M7); deleting a circulation must never be able to remove the
            // reason a promotion was reserved.
            $table->foreignId('dpc_proceeding_hold_id')
                ->constrained('dpc_proceeding_holds', 'id', 'fk_dpc_circ_item_hold')
                ->restrictOnDelete();

            // Snapshots, so the item stays readable if the source row goes
            // (AD-5). `reason_code` is the ground **as it stood when raised**.
            $table->unsignedSmallInteger('sn');
            $table->string('officer_name');
            $table->string('reason_code', 30);

            $table->text('resolution_detail');
            $table->string('resolution_reference')->nullable();
            $table->date('resolution_received_on');

            // Phase 2. `decision` is left an unenumerated nullable string here:
            // the enum arrives with the transitions that set it.
            $table->string('decision', 20)->nullable();
            $table->foreignId('decided_by')->nullable()
                ->constrained('users', 'id', 'fk_dpc_circ_item_decided_by')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('hold_released_at')->nullable();

            $table->timestamps();

            $table->unique(['dpc_circulation_id', 'dpc_proceeding_hold_id'], 'dpc_circ_item_unique');
            $table->index('dpc_proceeding_hold_id', 'dpc_circ_item_hold_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_circulation_items');
        Schema::dropIfExists('dpc_circulations');
    }
};
