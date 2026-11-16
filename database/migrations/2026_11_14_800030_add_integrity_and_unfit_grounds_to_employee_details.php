<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Employee details, business decisions of 2026-11-14:
 *
 * - **Integrity (सत्यनिष्ठा)** per CR year, chosen beside the grade from an
 *   Administrator master whose every option says whether it is Fit or Unfit.
 * - A missing-record reason may make the officer **Unfit** ("appointed in this
 *   year": no CR exists, ever, so none is asked for).
 * - The Department Enquiry/Court Case section's two questions — a case ongoing, a
 *   punishment in effect — each answered with a document; a Yes is Unfit.
 *
 * Additive: a new master, nullable columns, and one flag set on the seeded
 * "Newly Appointed" reason.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: int}>
     */
    private const OPTIONS = [
        ['certified_good', 'Certified + No Adverse Remarks and/or Good Grade', 'प्रमाणित + कोई प्रतिकूल टिप्पणी नहीं और/या अच्छा ग्रेड', true, 10],
        ['not_certified_adverse', 'Not Certified + Adverse Remarks and/or Bad Grade', 'प्रमाणित नहीं + प्रतिकूल टिप्पणी और/या खराब ग्रेड', false, 20],
        ['not_mentioned_good', 'Not Mentioned + No Adverse Remarks and/or Good Grade', 'उल्लेख नहीं + कोई प्रतिकूल टिप्पणी नहीं और/या अच्छा ग्रेड', true, 30],
        ['not_mentioned_adverse', 'Not Mentioned + Adverse Remarks and/or Bad Grade', 'उल्लेख नहीं + प्रतिकूल टिप्पणी और/या खराब ग्रेड', false, 40],
    ];

    public function up(): void
    {
        Schema::create('integrity_options', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');
            // true: the officer is Fit on this answer; false: Unfit.
            $table->boolean('fit')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();

        foreach (self::OPTIONS as [$code, $en, $hi, $fit, $order]) {
            DB::table('integrity_options')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name_en' => $en,
                'name_hi' => $hi,
                'fit' => $fit,
                'sort_order' => $order,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->foreignId('integrity_option_id')->nullable()->constrained('integrity_options')->restrictOnDelete();
        });

        Schema::table('record_reasons', function (Blueprint $table): void {
            // A reason that leaves no record to produce, ever: the officer is Unfit and no document is asked.
            $table->boolean('makes_unfit')->default(false);
        });

        DB::table('record_reasons')->where('code', 'newly_appointed')->update(['makes_unfit' => true]);
        DB::table('record_reasons')->where('code', 'newly_appointed')->where('name_en', 'Newly Appointed')
            ->update(['name_en' => 'Appointed in this year', 'name_hi' => 'इसी वर्ष नियुक्त']);

        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            // Null: not answered.
            $table->boolean('enquiry_ongoing')->nullable();
            $table->boolean('punishment_effective')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropColumn(['enquiry_ongoing', 'punishment_effective']);
        });

        Schema::table('record_reasons', function (Blueprint $table): void {
            $table->dropColumn('makes_unfit');
        });

        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('integrity_option_id');
        });

        Schema::dropIfExists('integrity_options');
    }
};
