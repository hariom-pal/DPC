<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoM of 2026-10-29:
 *  - the AO's declaration on submitting a Pre-DPC;
 *  - the Government Order / assignment document a Department Admin uploads on
 *    allotting an AO / AH, and the declaration made with it.
 *
 * Additive: nullable columns. Rows before this carry none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->timestamp('declaration_accepted_at')->nullable()->after('submitted_at');
        });

        Schema::table('proposal_allotments', function (Blueprint $table): void {
            $table->string('order_disk', 40)->nullable()->after('reason');
            $table->string('order_path')->nullable()->after('order_disk');
            $table->string('order_name')->nullable()->after('order_path');
            $table->char('order_sha256', 64)->nullable()->after('order_name');
            $table->timestamp('declaration_accepted_at')->nullable()->after('order_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_allotments', function (Blueprint $table): void {
            $table->dropColumn(['order_disk', 'order_path', 'order_name', 'order_sha256', 'declaration_accepted_at']);
        });

        Schema::table('pre_dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('declaration_accepted_at');
        });
    }
};
