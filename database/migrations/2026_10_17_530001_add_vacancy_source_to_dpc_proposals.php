<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each proposal's vacancy figures come from.
 *
 *  - `chart` — the AO's Working Officers chart (every proposal raised before this
 *    migration; backfilled so their figures never change).
 *  - `vacancy_master` — the Administrator's finalized Vacancy Management record.
 *
 * Set once, when a proposal is created, from `dpc.vacancy_master.source_for_new_proposals`.
 * Additive: one nullable column; no other row or column is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->string('vacancy_source', 20)->nullable();
        });

        DB::table('dpc_proposals')->update(['vacancy_source' => 'chart']);
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropColumn('vacancy_source');
        });
    }
};
