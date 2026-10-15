<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Reason Master, and the justification a missing record now requires
 * (change register of 2026-08-27, items 14 to 17 and 19).
 *
 * ### What the register asks for
 *
 * > *Five CRs are mandatory. CR years do NOT need to be continuous. Whenever any
 * > CR is missing, AO must upload Reason and Supporting PDF. Both are mandatory.*
 *
 * > *Loyalty Certificate belongs to the same year as the CR. If missing, AO must
 * > upload Reason and Supporting PDF.*
 *
 * > *Whenever any mandatory CR or Loyalty record is unavailable, AO shall upload
 * > Reason and Supporting PDF. Candidate shall continue in the DPC. Candidate
 * > Status = CIR.*
 *
 * So a gap is no longer simply an absence. It is a **recorded, justified and
 * evidenced** absence, and the candidate stays in the exercise because of it
 * rather than in spite of it.
 *
 * ### One table for both kinds of gap
 *
 * `kind` distinguishes a missing CR from a missing Loyalty Certificate, rather
 * than two tables differing only in a word. They carry identical information —
 * a year, a reason, remarks, a PDF — are validated identically, and produce
 * identical consequences (item 19 names them together). Two tables would be two
 * places to add the next field to and one of them would be missed.
 *
 * A **unique index on `(row, kind, year)`** is what makes a gap a statement about
 * one record rather than a list of attempts.
 *
 * ### Why the reason is a master and the remarks are a column
 *
 * > *Create a configurable Reason Master. If "Other" is selected, Remarks become
 * > mandatory.*
 *
 * The reason is chosen from a master so the Commission can report on *why* records
 * are missing across departments — which is the question a Reason Master exists to
 * answer, and which free text cannot. `requires_remarks` lives on the master row
 * rather than being hardcoded against a code named "Other", so the Administrator
 * can mark any reason as needing an explanation without a deployment.
 *
 * ### The PDF is evidence, and is treated as such
 *
 * `sha256` is recorded at upload, the same discipline as the proceeding documents,
 * the circulation documents and the Government Orders. A justification whose
 * supporting document cannot be shown to be the one that was uploaded is not a
 * justification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');

            /*
             | Whether choosing this reason obliges the officer to explain further.
             |
             | On the row rather than compared against the literal `other`, so the
             | Administrator can mark "Court Case" as needing the case number
             | without a code change — and so a Commission that renames "Other"
             | does not silently switch the rule off.
             */
            $table->boolean('requires_remarks')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->seedSuggestedReasons();

        Schema::create('dpc_evaluation_record_gaps', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('dpc_evaluation_row_id')
                ->constrained('dpc_evaluation_rows')
                ->cascadeOnDelete();

            // `cr` or `loyalty`. See the class docblock for why one table.
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year');

            /*
             | `restrictOnDelete`: a reason that has been used to justify a gap on a
             | calculated DPC must not be removable underneath it. The master soft
             | deletes, so this is reached only by a hard delete, and refusing is
             | the right answer.
             */
            $table->foreignId('record_reason_id')
                ->constrained('record_reasons')
                ->restrictOnDelete();

            $table->text('remarks')->nullable();

            // The supporting PDF. Mandatory in the request; nullable here only so
            // the row and its file are written in one transaction rather than two.
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One statement per missing record, not a list of attempts.
            $table->unique(['dpc_evaluation_row_id', 'kind', 'year'], 'dpc_eval_gap_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_evaluation_record_gaps');
        Schema::dropIfExists('record_reasons');
    }

    /**
     * The seven values the register suggests.
     *
     * Seeded in the migration rather than only in a seeder, because seeders do not
     * run on a live database and a Reason Master that is empty on the day this
     * ships makes every missing-record form unusable — the reason is mandatory, so
     * an empty master blocks the CIR path the register exists to open.
     *
     * `Other` is the only one marked `requires_remarks`, which is what the register
     * states. The rest are the Commission's to adjust.
     */
    private function seedSuggestedReasons(): void
    {
        $now = now();
        $rows = [];
        $order = 0;

        foreach ([
            ['record_not_available', 'Record Not Available', 'अभिलेख उपलब्ध नहीं', false],
            ['officer_on_deputation', 'Officer on Deputation', 'अधिकारी प्रतिनियुक्ति पर', false],
            ['department_delay', 'Department Delay', 'विभागीय विलंब', false],
            ['court_case', 'Court Case', 'न्यायालय प्रकरण', false],
            ['record_lost', 'Record Lost', 'अभिलेख गुम', false],
            ['newly_appointed', 'Newly Appointed', 'नव नियुक्त', false],
            ['other', 'Other', 'अन्य', true],
        ] as [$code, $en, $hi, $requiresRemarks]) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name_en' => $en,
                'name_hi' => $hi,
                'requires_remarks' => $requiresRemarks,
                'sort_order' => $order++,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('record_reasons')->insert($rows);
    }
};
