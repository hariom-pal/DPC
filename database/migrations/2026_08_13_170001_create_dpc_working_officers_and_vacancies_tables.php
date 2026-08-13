<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working Officers + Vacancy sub-types (PPT S16) — M4 Phase 3.
 *
 * `dpc_vacancies` is strictly row-based (architect directive, 2026-08-13):
 * one row per (vacancy_type, reservation_category) pair with a count —
 * never separate columns per vacancy type. Both FKs reference the M2
 * masters (`vacancy_types`, `reservation_categories`); no reservation or
 * vacancy value is ever hardcoded.
 *
 * `dpc_working_officers` is a proposal-time snapshot (architect directive):
 * plain columns, no live FK to any employee/HRMS record, so a historical
 * proposal can never change because upstream HR data changes later — there
 * is no such live source in this system to begin with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table) {
            // S16: "2026 की स्थिति में स्वीकृत उप सचिव पद संख्या" — sanctioned
            // post count on the target post, captured alongside the vacancy
            // breakdown, not at post selection (S14).
            $table->unsignedInteger('sanctioned_post_count')->nullable()->after('legal_stay');
        });

        Schema::create('dpc_vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('vacancy_type_id')->constrained('vacancy_types');
            $table->foreignId('reservation_category_id')->constrained('reservation_categories');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'vacancy_type_id', 'reservation_category_id'], 'dpc_vacancies_unique_row');
        });

        Schema::create('dpc_working_officers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->unsignedSmallInteger('sn');
            $table->string('name');
            $table->date('dob');
            $table->string('qualification')->nullable();
            $table->date('date_of_joining_service')->nullable();
            $table->date('date_of_posting_on_post')->nullable();
            $table->foreignId('reservation_category_id')->constrained('reservation_categories');
            $table->unsignedSmallInteger('roster_position')->nullable();
            $table->date('deputation_date')->nullable();
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'sn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_working_officers');
        Schema::dropIfExists('dpc_vacancies');

        Schema::table('dpc_proposals', function (Blueprint $table) {
            $table->dropColumn('sanctioned_post_count');
        });
    }
};
