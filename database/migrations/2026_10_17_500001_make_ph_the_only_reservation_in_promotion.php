<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * There is no reservation in promotion today except PH (Persons with Disabilities,
 * rule 2.1). The masters say so:
 *
 *  - Reservation Categories gains PH, active; every other category is set inactive.
 *    Nothing is deleted — rows that cite a category keep it.
 *  - Reservation Policies gains how a quota with a decimal part becomes a whole number
 *    (`rounding`: floor or ceil) and an optional supporting PDF. The PH policy — 3% of
 *    the promotion vacancies, decimal dropped — is recorded as the policy in force,
 *    the figure the portal applied from configuration until now. Every other policy
 *    and roster is set inactive.
 *  - `ph_reservation_rules` (created earlier today, never used) is dropped: the PH
 *    quota is a Reservation Policy, where an administrator looks for it.
 *
 * `down()` reactivates the five categories, removes the PH policy and category when
 * nothing cites them, and drops the added columns. Statuses that were inactive before
 * this migration cannot be told apart from the ones it set; restore the backup for those.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const OTHERS = ['UR', 'SC', 'ST', 'OBC', 'EWS'];

    public function up(): void
    {
        Schema::table('reservation_policies', function (Blueprint $table): void {
            $table->string('rounding', 10)->default('floor')->after('percentage');
            $table->string('disk', 40)->nullable()->after('effective_to');
            $table->string('path')->nullable()->after('disk');
            $table->string('original_name')->nullable()->after('path');
            $table->unsignedInteger('size_bytes')->nullable()->after('original_name');
            $table->char('sha256', 64)->nullable()->after('size_bytes');
        });

        $now = now();

        $ph = DB::table('reservation_categories')->where('code', 'PH')->value('id')
            ?? DB::table('reservation_categories')->insertGetId([
                'code' => 'PH',
                'name_en' => 'Persons with Disabilities (PH / PwD)',
                'name_hi' => 'दिव्यांगजन (PH)',
                'is_reserved' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('reservation_categories')->where('id', '!=', $ph)->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => $now]);
        DB::table('reservation_policies')->where('reservation_category_id', '!=', $ph)->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => $now]);
        DB::table('reservation_rosters')->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => $now]);

        if (! DB::table('reservation_policies')->where('reservation_category_id', $ph)->exists()) {
            DB::table('reservation_policies')->insert([
                'reservation_category_id' => $ph,
                'percentage' => (float) config('dpc.employee_details.ph_quota_percent', 3),
                'rounding' => 'floor',
                'effective_from' => '2000-01-01',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::dropIfExists('ph_reservation_rules');
    }

    public function down(): void
    {
        Schema::create('ph_reservation_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('effective_from_year')->index();
            $table->decimal('percentage', 5, 2);
            $table->string('rounding', 10)->default('floor');
            $table->string('order_number', 120);
            $table->date('order_date');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        DB::table('reservation_categories')->whereIn('code', self::OTHERS)->update(['status' => 'active']);

        $ph = DB::table('reservation_categories')->where('code', 'PH')->value('id');

        if ($ph !== null) {
            DB::table('reservation_policies')->where('reservation_category_id', $ph)->delete();

            $cited = collect(['dpc_vacancies', 'dpc_working_officers', 'dpc_evaluation_rows', 'dpc_seniority_entries', 'reservation_roster_points'])
                ->contains(fn (string $table): bool => Schema::hasTable($table)
                    && DB::table($table)->where('reservation_category_id', $ph)->exists());

            if (! $cited) {
                DB::table('reservation_categories')->where('id', $ph)->delete();
            }
        }

        Schema::table('reservation_policies', function (Blueprint $table): void {
            $table->dropColumn(['rounding', 'disk', 'path', 'original_name', 'size_bytes', 'sha256']);
        });
    }
};
