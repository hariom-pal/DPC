<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The simple Working Officers list matched against Vacancy Management (business
 * decision, 2026-11-01):
 *  - `dpc_working_officers.out_on_deputation`: one of our officers away on deputation
 *    at another department (the list's OUT ON DEPUTATION column, matched with B2);
 *  - `vacancy_position_entries.employee_number`: the officer's employee number on a
 *    B1/B2 row, optional, so a list row is matched by number before name.
 *
 * Additive and nullable: rows written before carry neither and read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->boolean('out_on_deputation')->nullable()->after('deputation_to');
        });

        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->string('employee_number', 60)->nullable()->after('officer_name');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn('employee_number');
        });

        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropColumn('out_on_deputation');
        });
    }
};
