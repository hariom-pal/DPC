<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-configurable lookup masters (architect Q4 hybrid): reservation
 * categories, review reasons, vacancy types and DPC types. Each carries the
 * standard status lifecycle + audit columns + soft deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['reservation_categories', 'review_reasons', 'vacancy_types', 'dpc_types'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name_en');
                $table->string('name_hi');
                if ($name === 'reservation_categories') {
                    $table->boolean('is_reserved')->default(true);
                }
                $table->string('status', 20)->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['dpc_types', 'vacancy_types', 'review_reasons', 'reservation_categories'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
