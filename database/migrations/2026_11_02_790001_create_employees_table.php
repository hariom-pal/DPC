<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee database (business decision, 2026-11-02): one row per Employee ID,
 * kept for future reference from everything the portal is told about an officer —
 * the Working Officers and Seniority Lists, Vacancy Management and the officer's
 * own account (email). Filled by `EmployeeDirectory`; never cleared with DPC data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_number', 60)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('qualification')->nullable();
            $table->string('current_post')->nullable();
            $table->date('date_of_joining_service')->nullable();
            $table->date('date_of_retirement')->nullable();
            // The department that last named the officer.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            // The officer's login, matched by Employee ID.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Where the latest details came from: working_list, seniority_list, vacancy_management, user_account.
            $table->string('source', 40);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
