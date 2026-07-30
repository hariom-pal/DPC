<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('signable_type');
            $table->unsignedBigInteger('signable_id');
            $table->string('channel', 10)->comment('email | sms — channel the signing OTP was delivered on');
            $table->string('otp_identifier')->comment('Verified email/mobile that authorised the action');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['signable_type', 'signable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_events');
    }
};
