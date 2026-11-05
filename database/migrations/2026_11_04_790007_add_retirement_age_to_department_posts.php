<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retirement age of a post, on its Department Post Mapping (2026-11-04), and the
 * date of birth a Section C retirement row is calculated from: the officer retires on
 * the last day of the month in which that age is reached (the day before, for a
 * birthday on the 1st).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_posts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retirement_age')->nullable()->after('promotion_duration_years');
        });

        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('employee_number');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn('date_of_birth');
        });

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropColumn('retirement_age');
        });
    }
};
