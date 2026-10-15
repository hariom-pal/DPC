<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DPC is raised **from an approved Pre-DPC** and gains a **revision number**.
 *
 * Business ruling, 2026-08-24 (change items 11, 12, 18 and 19).
 *
 * **Item 11 — the DPC inherits, it does not re-ask.** There is no standalone "New
 * DPC" any more: a DPC exists because an active approved Pre-DPC sanctioned that
 * promotion exercise, and it takes its Department, DPC Year, Promotion From Post,
 * Promotion To Post, Recruitment Rule, Class, Pay Scale and Promotion Duration
 * from it. `pre_dpc_proposal_id` is the reference that makes that inheritance a
 * fact rather than a copy nobody can trace, and it is `RESTRICT` on delete: the
 * order a promotion was raised under must not disappear from under it.
 *
 * The inherited values are **stored, not read live**, for the same reason the
 * Pre-DPC stores them: an approved DPC states the terms it ran under, and a later
 * amendment to the Pre-DPC must not silently restate a concluded promotion.
 *
 * **Item 12 — the Proposal Date is generated.** The column stays; what goes is
 * the officer typing it. Handled in the service, not here.
 *
 * **Items 18 and 19 — revisions, not new numbers.** A return for correction bumps
 * `revision_number`; the DPC Number never changes. `dpc_proposal_revisions` holds
 * one row per revision — who submitted it, who returned it, why, and with what
 * remarks — and rows are appended, never edited, so an earlier revision is
 * read-only by construction. A genuinely new exercise (additional vacancies, a
 * Review DPC, a court-ordered DPC, a fresh exercise) is a *new proposal* and
 * therefore gets a new number at revision 1 — which needs no column, because it is
 * simply another row.
 *
 * **`dpc_code` moves from approval to creation.** Item 18's example numbers a
 * proposal that has been returned twice, so the number must exist before the first
 * approval. Codes already issued are untouched — a DPC's number never changes once
 * it has one — so this migration backfills nothing into `dpc_code`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            // Item 11. Nullable because proposals created before this ruling were
            // raised without one, and inventing a Pre-DPC for them would be
            // inventing the order that sanctioned them.
            $table->foreignId('pre_dpc_proposal_id')->nullable()->after('department_id')
                ->constrained('pre_dpc_proposals')->restrictOnDelete();

            $table->unsignedSmallInteger('dpc_year')->nullable()->after('pre_dpc_proposal_id');

            // Inherited from the Pre-DPC, which took them from the two posts.
            $table->foreignId('pay_scale_id')->nullable()->after('promotion_from_post_id')
                ->constrained('pay_scales')->nullOnDelete();
            $table->string('class_level', 5)->nullable()->after('pay_scale_id');
            $table->unsignedSmallInteger('promotion_duration_years')->nullable()->after('class_level');

            // The rule in force when this DPC was raised. A rule is versioned and
            // never overwritten, so naming the version is what lets a concluded
            // DPC still say which rule it ran under.
            $table->foreignId('recruitment_rule_id')->nullable()->after('promotion_duration_years')
                ->constrained('recruitment_rules')->nullOnDelete();

            // Item 18.
            $table->unsignedSmallInteger('revision_number')->default(1)->after('status');
        });

        Schema::create('dpc_proposal_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');

            // The register's list, in its order.
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20);

            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'revision_number'], 'dpc_revision_unique');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proposal_revisions');

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recruitment_rule_id');
            $table->dropConstrainedForeignId('pay_scale_id');
            $table->dropConstrainedForeignId('pre_dpc_proposal_id');
            $table->dropColumn(['dpc_year', 'class_level', 'promotion_duration_years', 'revision_number']);
        });
    }

    /**
     * Give every existing proposal its DPC Year and its first revision row.
     *
     * **`pre_dpc_proposal_id` is deliberately left null.** It could be guessed by
     * matching the department and the post pair against an approved Pre-DPC, and
     * that guess would be wrong exactly when it matters — an old proposal for a
     * pair that has since been re-sanctioned would be attributed to an order that
     * did not exist when it was raised. A null says "raised before item 11", which
     * is true; a guess would say something false and unfalsifiable.
     *
     * The revision row is not a guess: every existing proposal is, by definition,
     * at revision 1, and the submission and return facts are already on the
     * proposal itself.
     */
    private function backfill(): void
    {
        foreach (DB::table('dpc_proposals')->orderBy('id')->get() as $proposal) {
            DB::table('dpc_proposals')->where('id', $proposal->id)->update([
                'dpc_year' => (int) date('Y', strtotime((string) $proposal->proposal_date)),
            ]);

            DB::table('dpc_proposal_revisions')->insert([
                'dpc_proposal_id' => $proposal->id,
                'revision_number' => 1,
                'submitted_at' => $proposal->submitted_at,
                'submitted_by' => $proposal->prepared_by,
                'returned_at' => $proposal->returned_at,
                'returned_by' => $proposal->returned_at === null ? null : $proposal->approved_by,
                'return_reason' => $proposal->return_remarks,
                'remarks' => 'Opened by migration on 2026-08-24: every proposal predating revision management is revision 1.',
                'status' => $proposal->status,
                'created_at' => $proposal->created_at,
                'updated_at' => now(),
            ]);
        }
    }
};
