<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-DPC Proposal (PPT S7–S11): the department's posts sanctioned for
 * promotion, prepared by the Authorized Officer and approved by the
 * Administrative Head. Versioned and immutable once approved (architect Q3);
 * approval drives the Cadre + Recruitment Rule masters (architect Q1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_dpc_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_version_id')->nullable()->constrained('pre_dpc_proposals')->nullOnDelete();
            $table->boolean('is_current')->default(false)->index();
            $table->string('status', 20)->default('draft')->index();
            $table->string('government_order_number')->nullable();
            $table->date('effective_from')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_remarks')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['department_id', 'version']);
        });

        Schema::create('pre_dpc_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_dpc_proposal_id')->constrained('pre_dpc_proposals')->cascadeOnDelete();
            $table->string('post_name');
            $table->string('class_level', 5);
            $table->foreignId('pay_scale_id')->nullable()->constrained('pay_scales')->nullOnDelete();
            $table->foreignId('cadre_id')->nullable()->constrained('cadres')->nullOnDelete();
            $table->decimal('direct_recruitment_pct', 5, 2)->default(0);
            $table->decimal('promotion_recruitment_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pre_dpc_post_feeders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_dpc_post_id')->constrained('pre_dpc_posts')->cascadeOnDelete();
            $table->string('feeder_name');
            $table->foreignId('feeder_cadre_id')->nullable()->constrained('cadres')->nullOnDelete();
            $table->decimal('promotion_percentage', 5, 2)->default(0);
            $table->unsignedSmallInteger('min_experience_years')->default(0);
            $table->string('min_qualification')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_dpc_post_feeders');
        Schema::dropIfExists('pre_dpc_posts');
        Schema::dropIfExists('pre_dpc_proposals');
    }
};
