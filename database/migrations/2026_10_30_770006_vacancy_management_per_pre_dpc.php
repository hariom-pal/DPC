<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacancy Management per Pre-DPC (decisions of 2026-10-30):
 *  - a record belongs to the approved Pre-DPC whose post and year it counts;
 *  - an officer of ours on deputation elsewhere carries the department's declaration
 *    whether a promotion has already been made against that post;
 *  - the working strength is one figure, its order and PDF optional.
 *
 * Additive, apart from relaxing NOT NULL on the working strength's order columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->foreignId('pre_dpc_proposal_id')->nullable()->after('department_post_id')
                ->constrained('pre_dpc_proposals')->nullOnDelete();
        });

        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->boolean('promotion_made')->nullable()->after('deputation_from');
        });

        Schema::table('vacancy_position_working_strengths', function (Blueprint $table): void {
            $table->string('order_number', 120)->nullable()->change();
            $table->date('order_date')->nullable()->change();
            $table->string('disk', 40)->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('original_name')->nullable()->change();
            $table->unsignedInteger('size_bytes')->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_position_entries', function (Blueprint $table): void {
            $table->dropColumn('promotion_made');
        });

        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pre_dpc_proposal_id');
        });
    }
};
