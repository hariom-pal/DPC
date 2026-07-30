<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every workflow transition across all workflow entities
 * (tamper-evident history — never overwritten). Distinct from owen-it field
 * audits and the human-readable Activity Timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('workflow', 60);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('event', 40);
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('workflow');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
