<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Self-Inspection was removed (2026-11-20): an officer under consideration uses the
 * Candidate Portal. Its access log is dropped — but only when it holds nothing. A log
 * with rows is a record of who looked at what, and this migration does not destroy
 * it: it leaves the table in place, unused, and says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('self_inspection_accesses')) {
            return;
        }

        if (DB::table('self_inspection_accesses')->exists()) {
            Log::warning('self_inspection_accesses holds rows: kept (unused). Removing it needs a decision.');

            return;
        }

        Schema::drop('self_inspection_accesses');
    }

    public function down(): void
    {
        // Nothing to restore: the table held nothing, and Self-Inspection is gone.
    }
};
