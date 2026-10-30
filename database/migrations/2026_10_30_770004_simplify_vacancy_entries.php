<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacancy Management simplified (MoM of 2026-10-29, decisions of 2026-10-30):
 *  - an entry's order and PDF are optional;
 *  - a deputation row names the officer and the date they left (rule 2.3);
 *  - any anticipated row may name its officer.
 *
 * Additive, apart from relaxing NOT NULL on the order and document columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->string('order_number', 120)->nullable()->change();
            $table->date('order_date')->nullable()->change();
            $table->string('disk', 40)->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('original_name')->nullable()->change();
            $table->unsignedInteger('size_bytes')->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();

            $table->string('officer_name')->nullable()->after('vacancy_count');
            $table->date('deputation_from')->nullable()->after('cadre_details');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn(['officer_name', 'deputation_from']);
        });
    }
};
