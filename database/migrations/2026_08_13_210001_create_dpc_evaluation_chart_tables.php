<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation / Tabulation Chart (PPT S18, approved at S25) — M4 Phase 5.
 *
 * `dpc_evaluation_rows` is a proposal-time employee snapshot and carries the
 * full standard ten-field set (architect directive, 2026-08-13), exactly as
 * `dpc_working_officers` and `dpc_seniority_entries` do: plain columns, no
 * live FK to any employee/HRMS record, so a historical proposal can never
 * change because upstream data changes later. `reservation_category_id` is
 * the one FK — it points at M2 reference data, not at live employee data.
 *
 * Loyalty, Immovable Property, and CR are each stored per year (decision Q4:
 * the S18 mockup shows year columns, not a single flag), as three child
 * tables keyed by (row, year). All three value columns are nullable: a
 * Circulation DPC exists precisely because an officer is missing CR years or
 * immovable-property returns, so "year present but value not recorded" must
 * be representable and is distinct from "year absent".
 *
 * Deliberately absent: `mark` and `remark`. Both are computed outputs and
 * belong to the M5 Eligibility Engine — M4 stores raw data only (architect
 * directive). No service years, experience, or zone-of-consideration column
 * appears here for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_evaluation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->unsignedSmallInteger('sn');

            // Standard ten-field employee snapshot.
            $table->string('employee_number')->nullable();
            $table->string('name');
            $table->date('dob');
            $table->string('qualification')->nullable();
            $table->string('current_post')->nullable();
            $table->string('feeder_post')->nullable();
            $table->foreignId('reservation_category_id')->constrained('reservation_categories');
            $table->date('date_of_joining_service')->nullable();
            $table->date('date_of_posting_on_current_post')->nullable();
            $table->date('date_of_posting_on_feeder_post')->nullable();

            // S18-specific raw inputs. `tribal_area_service_years` is the raw
            // count the officer records; the "+1" bonus it feeds is M5's.
            $table->unsignedSmallInteger('tribal_area_service_years')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'sn']);
        });

        Schema::create('dpc_evaluation_cr_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('grade', 10)->nullable();
            $table->timestamps();

            $table->unique(['dpc_evaluation_row_id', 'year'], 'dpc_eval_cr_row_year_unique');
        });

        Schema::create('dpc_evaluation_loyalty_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->boolean('flag')->nullable();
            $table->timestamps();

            $table->unique(['dpc_evaluation_row_id', 'year'], 'dpc_eval_loyalty_row_year_unique');
        });

        Schema::create('dpc_evaluation_property_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_evaluation_row_id')->constrained('dpc_evaluation_rows')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->boolean('flag')->nullable();
            $table->timestamps();

            $table->unique(['dpc_evaluation_row_id', 'year'], 'dpc_eval_property_row_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_evaluation_property_flags');
        Schema::dropIfExists('dpc_evaluation_loyalty_flags');
        Schema::dropIfExists('dpc_evaluation_cr_grades');
        Schema::dropIfExists('dpc_evaluation_rows');
    }
};
