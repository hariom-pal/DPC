<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pre-DPC and its Vacancy Management record go to the AH together (2026-11-19):
 * the record keeps which Pre-DPC submission it went with, and the figures the AO
 * signed with their hash — so a change after the eSign is caught before approval.
 *
 * Nullable and additive: existing records, finalized or draft, are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->unsignedBigInteger('submitted_with_pre_dpc_id')->nullable()->after('pre_dpc_proposal_id')->index();
            $table->json('submission_snapshot')->nullable()->after('submitted_at');
            $table->string('submission_sha256', 64)->nullable()->after('submission_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->dropIndex(['submitted_with_pre_dpc_id']);
            $table->dropColumn(['submitted_with_pre_dpc_id', 'submission_snapshot', 'submission_sha256']);
        });
    }
};
