<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rule 2.2 of the Coder Instructions — departmental enquiry and the sealed cover
 * (business decision, 2026-10-21).
 *
 * `sealed_cover_reasons` is a master the Administrator maintains: each reason says
 * whether the committee's finding on an officer it applies to is kept in a sealed
 * cover (`sealed_cover`) or the officer may be promoted (`no_effect`). The four the
 * rule names are seeded:
 *
 *   - a departmental enquiry instituted and a charge-sheet issued → sealed cover;
 *   - a police charge-sheet filed before a court → sealed cover;
 *   - only a show-cause notice served, no penalty order → may be promoted;
 *   - under suspension without a charge-sheet → fit for promotion.
 *
 * The Authorized Officer answers, for every officer, whether any such matter is
 * pending (`vigilance_pending`), and if so which reason, its date, remarks and the
 * order (a document of kind `vigilance_order`).
 *
 * Additive: a new table and nullable columns; nothing existing changes.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const REASONS = [
        ['de_chargesheet', 'Departmental enquiry instituted and charge-sheet issued', 'विभागीय जाँच प्रारंभ एवं आरोप-पत्र जारी', 'sealed_cover', 10],
        ['police_chargesheet', 'Police charge-sheet filed before a court', 'पुलिस द्वारा न्यायालय में आरोप-पत्र प्रस्तुत', 'sealed_cover', 20],
        ['show_cause_only', 'Only a show-cause notice served; no penalty order passed', 'केवल कारण बताओ नोटिस जारी; कोई दंडात्मक आदेश पारित नहीं', 'no_effect', 30],
        ['suspended_no_chargesheet', 'Under suspension, no charge-sheet issued', 'निलंबित, आरोप-पत्र जारी नहीं', 'no_effect', 40],
    ];

    public function up(): void
    {
        Schema::create('sealed_cover_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');

            // `sealed_cover`: the finding is kept in a sealed cover and the post held;
            // `no_effect`: the officer may be promoted.
            $table->string('effect', 20)->default('sealed_cover');
            $table->boolean('requires_document')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();

        foreach (self::REASONS as [$code, $en, $hi, $effect, $order]) {
            DB::table('sealed_cover_reasons')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name_en' => $en,
                'name_hi' => $hi,
                'effect' => $effect,
                'requires_document' => true,
                'sort_order' => $order,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            // Null: not answered; false: nothing pending; true: a matter is pending.
            $table->boolean('vigilance_pending')->nullable();
            $table->foreignId('sealed_cover_reason_id')->nullable()->constrained('sealed_cover_reasons')->restrictOnDelete();
            $table->date('vigilance_date')->nullable();
            $table->text('vigilance_remarks')->nullable();
            $table->foreignId('vigilance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vigilance_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sealed_cover_reason_id');
            $table->dropConstrainedForeignId('vigilance_recorded_by');
            $table->dropColumn(['vigilance_pending', 'vigilance_date', 'vigilance_remarks', 'vigilance_recorded_at']);
        });

        Schema::dropIfExists('sealed_cover_reasons');
    }
};
