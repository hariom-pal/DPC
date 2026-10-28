<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department Admin registration by invitation (business decision, 2026-10-26).
 *
 * The Portal Super Admin invites one official e-mail to become a department's
 * Department Admin. Only a SHA-256 of the one-time token is stored, so a copy of
 * this table cannot be used to register. An invitation is consumed once, expires,
 * and can be revoked; while the SMS gateway is unavailable it may carry the
 * Portal Super Admin's recorded waiver of the mobile OTP.
 *
 * Additive: a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            // The Portal Super Admin's waiver of the mobile OTP (SMS gateway down).
            $table->boolean('mobile_otp_waived')->default(false);

            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->string('delivery_error', 500)->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['department_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_invitations');
    }
};
