<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security log (Phase 1, 2026-10-27): sign-in, sign-out, OTP failures,
 * sessions ended by the account gate, and account (de)activation.
 *
 * Kept apart from the activity timeline because it records attempts as well as
 * outcomes — including attempts by nobody known — at a volume the timeline is not
 * for. Append-only: the model refuses updates and deletes. Never holds a password,
 * an OTP or the login identifier typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
