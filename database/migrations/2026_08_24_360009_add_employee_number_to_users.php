<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An officer's **employee number** on their account (change item 17).
 *
 * The register: *"Every officer appearing in the Seniority List must have login
 * access… If login already exists, Reuse it. Otherwise automatically create the
 * account."* Reuse needs something to recognise the officer by, and the Seniority
 * List carries exactly one identifier of a person — `employee_number`. Not a name:
 * two officers share one, and a promotion account matched by name is a promotion
 * account handed to the wrong person.
 *
 * **It is also the login handle**, because it has to be. A Seniority List carries
 * no email address and no mobile number, so an account created from one has no
 * channel to be reached on. Making the employee number a third accepted login
 * identifier is what lets such an account be used at all; the department hands the
 * temporary password over in person, which is the same route S49–S51 already
 * assumes for an officer under consideration.
 *
 * Nullable — no existing account has one, and an officer who has never appeared on
 * a Seniority List still may not. Unique, because the whole point is that it
 * identifies one person: two accounts sharing an employee number would make
 * "reuse it" ambiguous at the moment it matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_number', 60)->nullable()->unique()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['employee_number']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('employee_number');
        });
    }
};
