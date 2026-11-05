<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening a sealed cover (2026-11-05, BD-48 decided). The DPC's own Chairman records
 * the competent authority's decision on the officer — cleared or rejected — with its
 * order and evidence, and opens the cover:
 *
 * - cleared: a new promotion order for that officer, effective on the opening date;
 * - rejected before 31 December of the DPC year: the seat is offered to the next
 *   eligible candidate on the waiting list, recorded here so it is offered once;
 * - rejected after 31 December: no promotion against that seat through this DPC;
 * - opened on 31 December itself: not yet decided — flagged, nothing reallocated.
 *
 * A promotion order now says what it is: the DPC's regular order, or one issued on
 * a sealed cover's opening (for the cleared officer, or the replacement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_sealed_cover_openings', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->restrictOnDelete();
            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->restrictOnDelete();
            $table->foreignId('dpc_calculation_result_id')->constrained('dpc_calculation_results')->restrictOnDelete();
            $table->unsignedInteger('sn');
            $table->string('officer_name');
            $table->string('outcome', 20);
            $table->date('opened_on');
            $table->string('authority_name');
            $table->string('authority_order_number', 120);
            $table->date('authority_order_date');
            $table->text('remarks')->nullable();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            // Rejected: what happens to the seat.
            $table->string('replacement_status', 30)->nullable();
            $table->unsignedInteger('replacement_sn')->nullable();
            $table->string('replacement_name')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One opening per sealed cover, and a waiting officer offered one seat once.
            $table->unique(['dpc_proceeding_id', 'sn'], 'sealed_cover_openings_sn_unique');
            $table->unique(['dpc_proceeding_id', 'replacement_sn'], 'sealed_cover_openings_replacement_unique');
        });

        Schema::table('dpc_promotion_orders', function (Blueprint $table): void {
            $table->string('kind', 20)->default('regular')->after('dpc_proceeding_id');
            $table->date('effective_date')->nullable()->after('order_date');
            $table->foreignId('dpc_sealed_cover_opening_id')->nullable()->after('kind')->constrained('dpc_sealed_cover_openings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_promotion_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dpc_sealed_cover_opening_id');
            $table->dropColumn(['kind', 'effective_date']);
        });

        Schema::dropIfExists('dpc_sealed_cover_openings');
    }
};
