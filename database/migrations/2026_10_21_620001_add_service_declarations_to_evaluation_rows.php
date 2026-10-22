<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions, recorded per officer in Employee Details (2026-10-21):
 *
 *   - rule 4.4: the order number and date behind the officer's date of birth and
 *     date of joining, with the department's undertaking that they are correct;
 *   - rule 2.3: whether the officer is on deputation without a sanctioned setup —
 *     such an officer is not considered (Not Eligible), and the deputation order is
 *     uploaded;
 *   - rule 4.4: whether the officer refused promotion to this post (default No); a
 *     refusal is evidenced by the refusal letter and that DPC's order, and a refusal
 *     in the previous year makes the officer unfit (Not Eligible).
 *
 * Additive: nullable columns and one with a default; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->string('dob_order_number', 120)->nullable();
            $table->date('dob_order_date')->nullable();
            $table->string('joining_order_number', 120)->nullable();
            $table->date('joining_order_date')->nullable();
            $table->foreignId('service_record_undertaking_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('service_record_undertaking_at')->nullable();

            $table->boolean('unsanctioned_deputation')->nullable();
            $table->boolean('refused_promotion')->default(false);
            $table->unsignedSmallInteger('refused_promotion_year')->nullable();
            $table->foreignId('declarations_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declarations_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_record_undertaking_by');
            $table->dropConstrainedForeignId('declarations_recorded_by');
            $table->dropColumn([
                'dob_order_number', 'dob_order_date', 'joining_order_number', 'joining_order_date',
                'service_record_undertaking_at', 'unsanctioned_deputation', 'refused_promotion',
                'refused_promotion_year', 'declarations_recorded_at',
            ]);
        });
    }
};
