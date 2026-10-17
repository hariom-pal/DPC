<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CR marks, the qualifying scores and the PH reservation become the
 * Administrator's, version by version (rules 2.1, 3.4 and 3.5 of the Coder
 * Instructions: "effective as per Master data").
 *
 * Each version names the DPC year from which it applies and carries the order that
 * authorised it, with its PDF. Versions are never edited or deleted: a change is a
 * new version, so which rule applied to which DPC stays answerable. A DPC uses the
 * latest version whose first year is not after its own DPC year; with none recorded,
 * the built-in rules of `config/dpc.php` apply, exactly as before.
 *
 * Additive only: two new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cr_scoring_scales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->unsignedSmallInteger('effective_from_year')->index();

            // Rule 3.4: grade => marks.
            $table->json('grade_marks');
            $table->unsignedTinyInteger('scoring_years');
            $table->unsignedTinyInteger('tribal_area_bonus')->default(0);
            $table->string('disqualifying_grade', 10);
            // Per class pair ("I-I") => the grade the last year may not carry.
            $table->json('last_year_forbidden_grades');
            $table->string('super_seat_grade', 10);
            // Rule 3.5: class pair => minimum total, plus "default".
            $table->json('qualifying_scores');

            $this->documentColumns($table);
        });

        Schema::create('ph_reservation_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('effective_from_year')->index();

            // Rule 2.1: a percentage of the promotion vacancies.
            $table->decimal('percentage', 5, 2);
            // "the decimal part is disregarded even if it is .9": floor.
            $table->string('rounding', 10)->default('floor');

            $this->documentColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ph_reservation_rules');
        Schema::dropIfExists('cr_scoring_scales');
    }

    private function documentColumns(Blueprint $table): void
    {
        $table->string('order_number', 120);
        $table->date('order_date');
        $table->string('disk', 40);
        $table->string('path');
        $table->string('original_name');
        $table->unsignedInteger('size_bytes');
        $table->char('sha256', 64);
        $table->text('remarks')->nullable();

        $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('recorded_at');
        $table->timestamps();
    }
};
