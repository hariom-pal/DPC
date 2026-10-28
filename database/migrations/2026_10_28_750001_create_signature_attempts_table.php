<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every verified eSign attempt (2026-10-28): the AO's submission and the AH's
 * approval, successful or not, with the name the eSign provider asserted. A
 * failed attempt is what puts the "eSign issue" notice on the proposal for its
 * Department Admin to act on. Append-only; names encrypted at rest.
 *
 * Additive: a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('signable_type');
            $table->unsignedBigInteger('signable_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('outcome', 30);
            $table->text('asserted_name')->nullable();
            $table->text('portal_name')->nullable();
            $table->timestamps();

            $table->index(['signable_type', 'signable_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_attempts');
    }
};
