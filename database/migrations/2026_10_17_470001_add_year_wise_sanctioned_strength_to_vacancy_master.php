<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Vacancy Master records the sanctioned strength year by year.
 *
 * It was read from the Department Post Mapping in force on 1 January of the Vacancy
 * Year. A sanctioned strength can change from one year to the next on its own order,
 * without a new post mapping, so the CGPSC Administrator now enters it for each
 * Vacancy Year (1 January to 31 December), with the sanctioning order and its PDF —
 * the same discipline as the working strength.
 *
 * `vacancy_position_sanctioned_strengths` — every sanctioned strength recorded for a
 * position, with its order and document. Append-only: a correction is a new row, so
 * "what was sanctioned, on whose order, before it was corrected" stays answerable.
 * The position points at the one in force.
 *
 * Additive only: a new table and a nullable column. `vacancy_positions.sanctioned_strength`
 * keeps its meaning — the figure frozen at finalization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_position_sanctioned_strengths', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Named: the derived name would be 65 characters, one over MySQL's limit.
            $table->foreignId('vacancy_position_id')
                ->constrained('vacancy_positions', 'id', 'vacancy_sanctioned_position_foreign')
                ->cascadeOnDelete();

            // For the Vacancy Year, 1 January to 31 December.
            $table->unsignedInteger('sanctioned_total');

            $table->string('order_number', 120);
            $table->date('order_date');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);

            $table->text('remarks')->nullable();
            $table->text('correction_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->foreignId('sanctioned_strength_id')->nullable()->after('working_strength_id')
                ->constrained('vacancy_position_sanctioned_strengths')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sanctioned_strength_id');
        });

        Schema::dropIfExists('vacancy_position_sanctioned_strengths');
    }
};
