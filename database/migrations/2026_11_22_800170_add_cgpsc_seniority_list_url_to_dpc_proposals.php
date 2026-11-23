<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-22: the link to the Seniority List CGPSC publishes on its website, one per
 * DPC, entered by the Administrator and shown to the candidate above the list
 * uploaded to the DPC, so the two can be matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->string('cgpsc_seniority_list_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('cgpsc_seniority_list_url');
        });
    }
};
