<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Final Departmental View Regarding Integrity (2026-11-16).
 *
 * Some Integrity answers do not settle the question by themselves — "Not Certified
 * + No Adverse Remarks and/or Good Grade" and both "Not Mentioned" answers. For
 * them the department records its final view (Integrity Certified: Fit; Integrity
 * Not Certified: Unfit) with a supporting document, and that view, not the
 * answer, decides. The Administrator marks which answers ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrity_options', function (Blueprint $table): void {
            $table->boolean('needs_departmental_view')->default(false)->after('fit');
        });

        DB::table('integrity_options')->whereIn('code', ['not_mentioned_good', 'not_mentioned_adverse'])
            ->update(['needs_departmental_view' => true]);

        if (! DB::table('integrity_options')->where('code', 'not_certified_good')->exists()) {
            DB::table('integrity_options')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => 'not_certified_good',
                'name_en' => 'Not Certified + No Adverse Remarks and/or Good Grade',
                'name_hi' => 'प्रमाणित नहीं + कोई प्रतिकूल टिप्पणी नहीं और/या अच्छा ग्रेड',
                // Decided by the departmental view; the flag is not read for it.
                'fit' => false,
                'needs_departmental_view' => true,
                'sort_order' => 25,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            // 'certified' (Fit) or 'not_certified' (Unfit); null where none is asked.
            $table->string('integrity_departmental_view', 20)->nullable()->after('integrity_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_cr_grades', function (Blueprint $table): void {
            $table->dropColumn('integrity_departmental_view');
        });

        DB::table('integrity_options')->where('code', 'not_certified_good')
            ->whereNotExists(fn ($q) => $q->from('dpc_evaluation_cr_grades')->whereColumn('dpc_evaluation_cr_grades.integrity_option_id', 'integrity_options.id'))
            ->delete();

        Schema::table('integrity_options', function (Blueprint $table): void {
            $table->dropColumn('needs_departmental_view');
        });
    }
};
