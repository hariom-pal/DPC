<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CR, Loyalty and Property Declaration requirements of a promotion, set by the
 * Administrator on the Class Master.
 *
 *  - `cr_grade_values` — the Eligible CR Grades: the numeric values a CR may carry,
 *    each valid from a date.
 *  - `promotion_cr_rules` — for promotion into a class from the same class or the class
 *    below (I→I, II→I …), from a DPC year until a newer rule: how many years of CR,
 *    Loyalty and Property Declaration are needed, the qualifying (average) mark, the
 *    super-seat grade and the tribal posting bonus. Never edited: a change is a new rule.
 *  - `promotion_cr_rule_years` — one row per year before the DPC year (1 = the year
 *    just before it): the CR's minimum marks, and whether that year's CR, Loyalty and
 *    Property Declaration are mandatory.
 *
 * Settings only for now: the eligibility calculation does not read them yet.
 *
 * Drops `cr_scoring_scales` (created earlier today, never used), which these replace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cr_scoring_scales');

        Schema::create('cr_grade_values', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('value');
            $table->date('effective_from');
            $table->string('remarks', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['value', 'effective_from']);
        });

        Schema::create('promotion_cr_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('to_class', 10);
            $table->string('from_class', 10);
            $table->unsignedSmallInteger('effective_from_year');

            $table->unsignedTinyInteger('cr_years');
            $table->unsignedTinyInteger('loyalty_years');
            $table->unsignedTinyInteger('property_years');
            $table->decimal('qualifying_average', 6, 2);
            $table->unsignedSmallInteger('super_seat_grade')->nullable();
            $table->unsignedTinyInteger('tribal_bonus')->default(0);
            $table->text('remarks')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['to_class', 'from_class', 'effective_from_year'], 'promotion_cr_rules_pair_year');
        });

        Schema::create('promotion_cr_rule_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_cr_rule_id')->constrained('promotion_cr_rules')->cascadeOnDelete();
            // 1 = the year just before the DPC year, 2 = the one before that …
            $table->unsignedTinyInteger('years_before');
            $table->unsignedSmallInteger('cr_min_marks')->nullable();
            $table->boolean('cr_mandatory')->default(true);
            $table->boolean('loyalty_mandatory')->default(true);
            $table->boolean('property_mandatory')->default(true);
            $table->timestamps();

            $table->unique(['promotion_cr_rule_id', 'years_before'], 'promotion_cr_rule_years_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_cr_rule_years');
        Schema::dropIfExists('promotion_cr_rules');
        Schema::dropIfExists('cr_grade_values');

        Schema::create('cr_scoring_scales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->unsignedSmallInteger('effective_from_year')->index();
            $table->json('grade_marks');
            $table->unsignedTinyInteger('scoring_years');
            $table->unsignedTinyInteger('tribal_area_bonus')->default(0);
            $table->string('disqualifying_grade', 10);
            $table->json('last_year_forbidden_grades');
            $table->string('super_seat_grade', 10);
            $table->json('qualifying_scores');
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
        });
    }
};
