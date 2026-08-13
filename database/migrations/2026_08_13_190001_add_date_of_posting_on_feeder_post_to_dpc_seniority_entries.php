<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only per architect change control (2026-08-13): adds the PPT
 * S17/S24 "Date of Posting on [feeder post]" column that Phase 4's initial
 * field directive omitted. Nullable snapshot field, manual entry for now,
 * future-HRMS-import compatible — never a live reference. No existing
 * column on `dpc_seniority_entries` is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_seniority_entries', function (Blueprint $table) {
            $table->date('date_of_posting_on_feeder_post')->nullable()->after('date_of_joining_service');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_seniority_entries', function (Blueprint $table) {
            $table->dropColumn('date_of_posting_on_feeder_post');
        });
    }
};
