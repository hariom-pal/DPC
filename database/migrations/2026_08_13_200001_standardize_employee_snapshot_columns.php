<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-snapshot standardization (architect directive, 2026-08-13):
 * every proposal snapshot table carries the same ten officer fields —
 * Employee Number, Employee Name, Date of Birth, Qualification, Current
 * Designation, Feeder Designation, Reservation Category, Date of Joining
 * Service, Date of Posting on Current Post, Date of Posting on Feeder Post.
 *
 * Strictly additive per change control: nothing already approved in Phase 3
 * (`dpc_working_officers`) or Phase 4 (`dpc_seniority_entries`) is renamed,
 * retyped, or dropped. All new columns are nullable snapshot fields —
 * manual entry today, HRMS-import compatible later, never a live reference.
 *
 * One naming divergence is deliberate and unavoidable: Phase 3 shipped the
 * "Date of Posting on Current Post" column as `date_of_posting_on_post`,
 * and renaming an approved column would be a breaking change. The seniority
 * table therefore gets `date_of_posting_on_current_post` while working
 * officers keeps `date_of_posting_on_post` for the same concept. Flagged in
 * docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md for a ruling — a rename needs explicit approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table) {
            $table->string('employee_number')->nullable()->after('sn');
            $table->string('current_post')->nullable()->after('qualification');
            $table->string('feeder_post')->nullable()->after('current_post');
            $table->date('date_of_posting_on_feeder_post')->nullable()->after('date_of_posting_on_post');
        });

        Schema::table('dpc_seniority_entries', function (Blueprint $table) {
            $table->date('date_of_posting_on_current_post')->nullable()->after('date_of_joining_service');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table) {
            $table->dropColumn([
                'employee_number',
                'current_post',
                'feeder_post',
                'date_of_posting_on_feeder_post',
            ]);
        });

        Schema::table('dpc_seniority_entries', function (Blueprint $table) {
            $table->dropColumn('date_of_posting_on_current_post');
        });
    }
};
