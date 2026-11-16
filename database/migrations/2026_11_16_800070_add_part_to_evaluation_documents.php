<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One upload for each CR of a year (2026-11-16).
 *
 * A year can hold up to four CRs, each with its own grade; each is now uploaded on
 * its own. `part` is the CR's number in the year (1 to 4). Documents already on
 * file keep null, which is read as the year's first CR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_evaluation_documents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('part')->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_evaluation_documents', function (Blueprint $table): void {
            $table->dropColumn('part');
        });
    }
};
