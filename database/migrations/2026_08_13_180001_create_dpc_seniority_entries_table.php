<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seniority List (PPT S17) — M4 Phase 4. A proposal-time snapshot
 * (architect directive, 2026-08-13): every officer field is a plain
 * column, no live FK to any employee/HRMS record, so a historical
 * proposal can never change because the employee master or an HRMS
 * changes later. `current_post`/`feeder_post` are captured as text
 * snapshots for the same reason — even though every row on one proposal
 * shares the same feeder cadre, the value is stored per-row rather than
 * joined back to the parent, so this table stays self-contained.
 * `reservation_category_id` is the one exception — it references the M2
 * master (config/reference data, not "live employee data"), consistent
 * with every other officer table in this build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_seniority_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->unsignedSmallInteger('seniority_number');
            $table->string('employee_number')->nullable();
            $table->string('name');
            $table->date('dob');
            $table->string('qualification')->nullable();
            $table->string('current_post')->nullable();
            $table->string('feeder_post')->nullable();
            $table->foreignId('reservation_category_id')->constrained('reservation_categories');
            $table->date('date_of_joining_service')->nullable();
            $table->timestamps();

            $table->unique(['dpc_proposal_id', 'seniority_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_seniority_entries');
    }
};
