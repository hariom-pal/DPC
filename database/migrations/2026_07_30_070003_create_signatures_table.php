<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('signable_type');
            $table->unsignedBigInteger('signable_id');
            $table->string('provider', 40)->comment('mock | csc_digisign | …');
            $table->string('status', 20)->default('pending')->comment('pending | signed | failed');
            $table->string('transaction_reference')->nullable();
            $table->string('certificate_subject')->nullable();
            $table->string('certificate_serial')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('signed_disk', 40)->nullable();
            $table->string('signed_path')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index(['signable_type', 'signable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
