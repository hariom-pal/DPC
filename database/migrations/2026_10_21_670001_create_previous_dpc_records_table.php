<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 1.1 (business decisions, 2026-10-21): a DPC held offline
 * before this portal is entered by the Commission's staff (role `cgpsc_staff`) — its
 * department and posts, year and date, reference, minutes, promotion order and the
 * officers it promoted. The final seniority list and the working officers of the
 * promotion post are uploaded too, but stay optional when fewer than 50 employees
 * were eligible.
 *
 * A department's rule 1.1 reconciliation can then cite the record instead of
 * uploading the minutes again (`dpc_proposal_verifications.previous_dpc_record_id`).
 *
 * Additive: a new table and a nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('previous_dpc_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('promotion_from_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->foreignId('promotion_to_post_id')->constrained('department_posts')->restrictOnDelete();
            $table->unsignedSmallInteger('dpc_year');
            $table->date('held_on');
            $table->string('reference', 120);
            $table->string('order_number', 120)->nullable();
            $table->date('order_date')->nullable();
            $table->unsignedInteger('eligible_count');
            // [{employee_number, name}] — the officers the DPC promoted.
            $table->json('promoted_officers');
            $table->text('remarks')->nullable();

            foreach (['minutes', 'seniority_list', 'working_officers'] as $file) {
                $table->string($file.'_disk', 40)->nullable();
                $table->string($file.'_path')->nullable();
                $table->string($file.'_original_name')->nullable();
                $table->char($file.'_sha256', 64)->nullable();
            }

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['department_id', 'promotion_to_post_id']);
        });

        Schema::table('dpc_proposal_verifications', function (Blueprint $table): void {
            $table->foreignId('previous_dpc_record_id')->nullable()->constrained('previous_dpc_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposal_verifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_dpc_record_id');
        });

        Schema::dropIfExists('previous_dpc_records');
    }
};
