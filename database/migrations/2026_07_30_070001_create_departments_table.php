<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name')->unique();
            $table->string('district')->nullable();
            $table->string('place')->nullable();
            $table->string('pin', 6)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('unregistered')->index();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::dropIfExists('departments');
    }
};
