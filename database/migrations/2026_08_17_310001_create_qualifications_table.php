<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Qualification master — the controlled list a recruitment rule's minimum
 * qualification is chosen from.
 *
 * Until now `recruitment_rule_feeders.min_qualification` was free text, so
 * "B.Ed.", "B.Ed", "B. Ed." and "Bachelor of Education" were four different
 * qualifications as far as the database was concerned. A Schedule-IV minimum
 * qualification is a finite, published list; it belongs in a master with the
 * same lifecycle every other reference master has.
 *
 * Same shape as the other lookup masters (code + bilingual name + status +
 * audit columns + soft deletes), with a `description` for the cases where the
 * short name is not self-explanatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};
