<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coder Instructions rule 4.3 (business decisions, 2026-10-21): the promotion order.
 *
 * Issued by the department's Administrative Head once the CGPSC has approved the
 * result: the AH enters the order number and date, the system renders the order as a
 * PDF listing the Main List officers (an officer under sealed cover is not promoted
 * and not listed), signs it with the AH's digital signature, and sends its link to
 * the officers and the committee members. Seniority runs from the order date.
 *
 * An issued order is never rewritten: a correction is a new order superseding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_promotion_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->restrictOnDelete();
            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->restrictOnDelete();

            $table->string('order_number', 120);
            $table->date('order_date');
            $table->unsignedSmallInteger('version');
            $table->foreignId('supersedes_id')->nullable()->constrained('dpc_promotion_orders')->nullOnDelete();

            // issued | superseded | failed
            $table->string('status', 20)->default('issued');
            $table->timestamp('superseded_at')->nullable();

            // The officers the order promotes, as the approved result listed them.
            $table->json('officers');

            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();
            $table->text('failure_reason')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_promotion_orders');
    }
};
