<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation hierarchy (architect Q8):
 * ReservationCategory → ReservationPolicy (effective-dated %) →
 * ReservationRoster (per department + cadre, configurable size) →
 * ReservationRosterPoint (ordered point → category). Percentages and roster
 * size are configuration-driven — never hardcoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_category_id')->constrained('reservation_categories')->cascadeOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reservation_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('cadre_id')->constrained('cadres')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('size');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['department_id', 'cadre_id', 'name']);
        });

        Schema::create('reservation_roster_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_roster_id')->constrained('reservation_rosters')->cascadeOnDelete();
            $table->unsignedSmallInteger('point_no');
            $table->foreignId('reservation_category_id')->constrained('reservation_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reservation_roster_id', 'point_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_roster_points');
        Schema::dropIfExists('reservation_rosters');
        Schema::dropIfExists('reservation_policies');
    }
};
