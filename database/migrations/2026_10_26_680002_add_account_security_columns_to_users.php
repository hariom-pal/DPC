<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security (business decision, 2026-10-26).
 *
 * `mobile_verification_waived_*`: while the SMS gateway is unavailable a mobile
 * number cannot be proved by OTP. Rather than mark it verified, the exception is
 * recorded — who granted it, when and why — and `mobile_verified_at` stays null
 * until a real OTP is verified.
 *
 * `password_changed_at`: when the account holder last chose their own password
 * (null while a system-issued temporary password is in force).
 *
 * Additive: nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('mobile_verification_waived_at')->nullable()->after('mobile_verified_at');
            $table->foreignId('mobile_verification_waived_by')->nullable()->after('mobile_verification_waived_at')
                ->constrained('users')->nullOnDelete();
            $table->string('mobile_verification_waiver_reason', 255)->nullable()->after('mobile_verification_waived_by');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mobile_verification_waived_by');
            $table->dropColumn(['mobile_verification_waived_at', 'mobile_verification_waiver_reason', 'password_changed_at']);
        });
    }
};
