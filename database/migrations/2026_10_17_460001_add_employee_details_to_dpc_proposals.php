<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Employee-wise details inside the Authorized Officer's DPC proposal (Coder
 * Instructions, points 1 to 3 — rules 1.6, 2.1, 3.1, 3.2, 4.1 and the undertakings on
 * pages 3 and 5).
 *
 * ### Additive only
 *
 * Every change is a new nullable column, a new table, or a NOT NULL relaxed to NULL.
 * No column is dropped, no row is deleted and no existing value is rewritten, so every
 * proposal recorded before this migration reads exactly as it did.
 *
 *  - `import_batches.list_as_on` — the same statement made with an uploaded list,
 *    held between the preview and the commit.
 *  - `dpc_proposals.seniority_list_as_on` — the date the department states its
 *    Seniority List was drawn up as on (rule 1.6), checked against 1 April of the
 *    year before the DPC year when the list is saved.
 *  - `reservation_category_id` on `dpc_seniority_entries` and `dpc_evaluation_rows`
 *    becomes nullable: the simplified Seniority List no longer carries a category,
 *    and the Authorized Officer records it on the employee's details instead. The
 *    foreign key is kept.
 *  - The PH questionnaire (rule 2.1) on `dpc_evaluation_rows`, as separate facts:
 *    PH status, previous benefit, current choice, verification and final decision.
 *  - The CR questionnaire of page 3 on `dpc_evaluation_cr_grades`: whether a CR was
 *    put up, whether it carries a grading, and up to four reports for the year.
 *  - The IPR details of rule 4.1 on `dpc_evaluation_property_flags`: submission date,
 *    the next-increment date the PDF uses to detect a duplicate IPR, and the
 *    department's decision on a late return.
 *  - `dpc_evaluation_documents` — every employee-wise document (CR copy, NRC, channel
 *    document, IPR, late-IPR decision, PH undertaking, other). A replacement
 *    supersedes the previous row instead of deleting it, so the history is kept.
 *  - Three missing-CR reasons named by rule 3.2 are added to the Reason Master when
 *    absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->date('seniority_list_as_on')->nullable();
        });

        // The "as on" date declared with an uploaded Seniority List, carried from the
        // preview to the commit so the date stored is the one the officer stated.
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->date('list_as_on')->nullable();
        });

        Schema::table('dpc_seniority_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('reservation_category_id')->nullable()->change();
        });

        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->unsignedBigInteger('reservation_category_id')->nullable()->change();

            // Rule 2.1 — the PH questionnaire, one fact per column.
            $table->boolean('is_ph')->nullable();
            $table->boolean('ph_previously_availed')->nullable();
            $table->boolean('ph_claims_reservation')->nullable();
            $table->string('ph_ineligibility_reason')->nullable();

            // Set to `pending` when a claim is recorded. Who verifies a claim and who
            // takes the final decision is not specified; the columns exist so the
            // concepts stay separate, and nothing writes a decision yet.
            $table->string('ph_verification_status', 20)->nullable();
            $table->string('ph_final_decision', 20)->nullable();
            $table->foreignId('ph_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ph_recorded_at')->nullable();

            // Page 3: "take undertaking that CR is signed as per channel".
            $table->foreignId('cr_channel_undertaking_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cr_channel_undertaking_at')->nullable();
        });

        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->boolean('put_up')->nullable();
            $table->boolean('has_grading')->nullable();
            // Up to four reports for the year: reporting, reviewing and accepting
            // officers' designations and grades.
            $table->json('reports')->nullable();
        });

        Schema::table('dpc_evaluation_property_flags', function (Blueprint $table): void {
            $table->date('submitted_on')->nullable();
            $table->date('next_increment_date')->nullable();
            // `accepted` | `rejected` — only for a return submitted after 31 January
            // of the following year.
            $table->string('late_decision', 20)->nullable();
            $table->text('late_decision_remarks')->nullable();
        });

        Schema::create('dpc_evaluation_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();

            // Null-on-delete, not cascade: an officer taken off the Seniority List
            // loses the link, not the record. The employee number lets the document
            // find the officer again if the list is corrected back.
            $table->foreignId('dpc_evaluation_row_id')->nullable()
                ->constrained('dpc_evaluation_rows')->nullOnDelete();
            $table->string('employee_number')->nullable();

            $table->string('kind', 40);
            $table->unsignedSmallInteger('year')->nullable();

            // The digital undertaking given with the upload (no duplicate CR for the
            // year; the PH undertaking being the employee's own signed copy).
            $table->timestamp('undertaking_confirmed_at')->nullable();
            $table->text('remarks')->nullable();

            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');

            // A replacement supersedes; it never deletes.
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('dpc_evaluation_documents')->nullOnDelete();

            $table->timestamps();

            $table->index(['dpc_evaluation_row_id', 'kind', 'year'], 'dpc_eval_doc_lookup');
            $table->index(['dpc_proposal_id', 'employee_number'], 'dpc_eval_doc_employee');
        });

        $this->addRule32Reasons();
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_evaluation_documents');

        Schema::table('dpc_evaluation_property_flags', function (Blueprint $table): void {
            $table->dropColumn(['submitted_on', 'next_increment_date', 'late_decision', 'late_decision_remarks']);
        });

        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->dropColumn(['put_up', 'has_grading', 'reports']);
        });

        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ph_recorded_by');
            $table->dropConstrainedForeignId('cr_channel_undertaking_by');
            $table->dropColumn([
                'is_ph', 'ph_previously_availed', 'ph_claims_reservation', 'ph_ineligibility_reason',
                'ph_verification_status', 'ph_final_decision', 'ph_recorded_at', 'cr_channel_undertaking_at',
            ]);
        });

        // The category columns are left nullable: re-imposing NOT NULL would fail on
        // any row recorded without one, and a rollback must not destroy data.

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('list_as_on');
        });

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('seniority_list_as_on');
        });
    }

    /**
     * Rule 3.2: the valid grounds for an unavailable CR — NRC issued, long leave, and
     * suspension — each with the document the PDF names. Inserted only when a reason
     * with the same code is absent, so an Administrator's own edits are never
     * overwritten.
     */
    private function addRule32Reasons(): void
    {
        $now = now();
        $order = (int) DB::table('record_reasons')->max('sort_order');

        foreach ([
            ['nrc_issued', 'NRC issued (upload the NRC)', 'अनापत्ति प्रमाण-पत्र (NRC) जारी (NRC अपलोड करें)'],
            ['long_leave', 'Employee on long leave (upload the leave approval)', 'कर्मचारी दीर्घ अवकाश पर (अवकाश स्वीकृति अपलोड करें)'],
            ['suspended', 'Employee suspended (upload the suspension and reinstatement orders)', 'कर्मचारी निलंबित (निलंबन एवं बहाली आदेश अपलोड करें)'],
        ] as [$code, $en, $hi]) {
            if (DB::table('record_reasons')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('record_reasons')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name_en' => $en,
                'name_hi' => $hi,
                'requires_remarks' => false,
                'sort_order' => ++$order,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
