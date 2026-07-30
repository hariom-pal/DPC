<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the free-text department.district and user.designation with FKs to
 * the new District and Designation masters (Place remains free text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('district');
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('name')->constrained('districts')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('name')->constrained('designations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['district_id']);
            $table->dropColumn('district_id');
            $table->string('district')->nullable()->after('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);
            $table->dropColumn('designation_id');
            $table->string('designation')->nullable()->after('name');
        });
    }
};
