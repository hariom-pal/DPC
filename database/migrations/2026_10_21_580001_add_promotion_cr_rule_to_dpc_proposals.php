<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Class Master promotion rule a DPC proposal is held to, fixed when the proposal
 * is raised (business decision, 2026-10-21): a rule the Administrator records later
 * does not change a DPC already raised.
 *
 * Additive and nullable: a proposal raised before this keeps resolving the rule in
 * force for its class pair and DPC year, as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->foreignId('promotion_cr_rule_id')->nullable()->after('recruitment_rule_id')
                ->constrained('promotion_cr_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_cr_rule_id');
        });
    }
};
