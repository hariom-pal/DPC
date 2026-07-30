<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned recruitment rules (architect directive): each cadre's Schedule-IV
 * promotion rules are versioned and never overwritten. A rule carries the
 * Government Order number, an effective window, direct/promotion percentages
 * and feeder cadres. The GO PDF is stored on the configured disk (S3 in prod);
 * only metadata lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadre_id')->constrained('cadres')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('government_order_number');
            $table->decimal('direct_recruitment_pct', 5, 2)->default(0);
            $table->decimal('promotion_recruitment_pct', 5, 2)->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('go_document_disk', 40)->nullable();
            $table->string('go_document_path')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cadre_id', 'version']);
        });

        Schema::create('recruitment_rule_feeders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_rule_id')->constrained('recruitment_rules')->cascadeOnDelete();
            $table->foreignId('feeder_cadre_id')->constrained('cadres')->cascadeOnDelete();
            $table->decimal('promotion_percentage', 5, 2)->default(0);
            $table->unsignedSmallInteger('min_experience_years')->default(0);
            $table->string('min_qualification')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_rule_feeders');
        Schema::dropIfExists('recruitment_rules');
    }
};
