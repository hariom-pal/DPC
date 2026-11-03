<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class Master: "Order" becomes "Level" (2026-11-03). The user found Order
 * unnecessary and asked that new classes can be added. Level is the class's place
 * in the hierarchy, 1 highest, which the promotion-hierarchy check reads instead of
 * a rank compiled into the code. The list is ordered by it too.
 *
 * Backfilled: I = 1, II = 2, III = 3; any other row after them in its old order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_classes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('level')->nullable()->after('name_hi');
        });

        $next = 3;
        $statutory = ['I' => 1, 'II' => 2, 'III' => 3];

        foreach (DB::table('service_classes')->orderBy('sort_order')->orderBy('code')->get(['id', 'code']) as $row) {
            DB::table('service_classes')->where('id', $row->id)->update(['level' => $statutory[$row->code] ?? ++$next]);
        }

        Schema::table('service_classes', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('service_classes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        DB::table('service_classes')->update(['sort_order' => DB::raw('level * 10')]);

        Schema::table('service_classes', function (Blueprint $table): void {
            $table->dropColumn('level');
        });
    }
};
