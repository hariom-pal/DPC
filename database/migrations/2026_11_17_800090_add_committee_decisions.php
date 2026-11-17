<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoM of 16 November 2026, points 2 and 3.
 *
 * - `dpc_committee_decisions`: each DPC Committee member's decision after
 *   evaluating the DPC — agreed, or returned to the AO with the sections to correct
 *   and a remark for each. Kept for good: every return and its remarks are history.
 * - `dpc_proposals.committee_returned_at` / `committee_return`: the return now
 *   open. While it is, the AO may change only the sections it names, and resubmits
 *   straight to the committee — the AH and the Secretary do not see it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpc_committee_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dpc_proposal_id')->constrained('dpc_proposals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('member_name');
            $table->string('decision', 20);
            $table->text('remarks')->nullable();
            $table->json('sections')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();

            $table->index(['dpc_proposal_id', 'decided_at']);
        });

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->timestamp('committee_returned_at')->nullable()->after('secretary_escalated_at');
            $table->json('committee_return')->nullable()->after('committee_returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn(['committee_returned_at', 'committee_return']);
        });
        Schema::dropIfExists('dpc_committee_decisions');
    }
};
