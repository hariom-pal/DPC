<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer and additional-charge requests (Phases 7–8, 2026-10-27).
 *
 * The receiving department's Department Admin asks for an existing officer, with
 * the order and the effective date; the CGPSC Super Admin approves or rejects; the
 * approved request takes effect on its date (`dpc:process-assignments`), once —
 * `status` moves submitted → approved → effective (or failed), under a row lock.
 *
 * `proposal_allotments.held_at` marks work left by an officer who moved: nobody can
 * act on it (the officer no longer holds the department) until the Department
 * Admin reassigns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 30);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('order_number', 100);
            $table->date('order_date');
            $table->string('document_disk', 30)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('resulting_assignment_id')->nullable()->constrained('user_department_assignments')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'effective_from']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('proposal_allotments', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('ended_by');
            $table->string('held_reason')->nullable()->after('held_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_allotments', function (Blueprint $table) {
            $table->dropColumn(['held_at', 'held_reason']);
        });
        Schema::dropIfExists('assignment_requests');
    }
};
