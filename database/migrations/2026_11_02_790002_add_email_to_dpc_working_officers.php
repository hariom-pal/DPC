<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Working Officers List's optional EMAIL column (business decision, 2026-11-02),
 * kept with the officer and passed on to the employee database. Nullable: earlier
 * lists carry none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_working_officers', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
    }
};
