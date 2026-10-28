<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One officer, several department assignments (Phase 6, 2026-10-27).
 *
 * Until now `uda_user_active_unique (user_id, active_flag)` allowed one active
 * posting per account. An officer may now hold their **primary** posting and,
 * approved by the CGPSC, an **additional charge** in another department — AO in
 * one, AH in another — while the same department can still hold only one active
 * assignment per officer (so never AO and AH in the same department):
 *
 * - `kind`: `primary` or `additional_charge`;
 * - `primary_flag`: 1 on the active primary posting, NULL otherwise, so
 *   `uda_user_primary_unique (user_id, primary_flag)` allows one active primary;
 * - `uda_user_dept_active_unique (user_id, department_id, active_flag)`: one active
 *   assignment per officer per department;
 * - `approved_by` / `approved_at`: the CGPSC Super Admin's approval, when given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_department_assignments', function (Blueprint $table) {
            $table->string('kind', 20)->default('primary')->after('role');
            $table->unsignedTinyInteger('primary_flag')->nullable()->after('active_flag');
            $table->foreignId('approved_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        DB::table('user_department_assignments')->whereNotNull('active_flag')->update(['primary_flag' => 1]);

        Schema::table('user_department_assignments', function (Blueprint $table) {
            $table->unique(['user_id', 'department_id', 'active_flag'], 'uda_user_dept_active_unique');
            $table->unique(['user_id', 'primary_flag'], 'uda_user_primary_unique');
        });

        Schema::table('user_department_assignments', function (Blueprint $table) {
            $table->dropUnique('uda_user_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_department_assignments', function (Blueprint $table) {
            $table->unique(['user_id', 'active_flag'], 'uda_user_active_unique');
        });

        Schema::table('user_department_assignments', function (Blueprint $table) {
            $table->dropUnique('uda_user_dept_active_unique');
            $table->dropUnique('uda_user_primary_unique');
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['kind', 'primary_flag', 'approved_by', 'approved_at']);
        });
    }
};
