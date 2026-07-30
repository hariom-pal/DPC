<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Committee masters (architect Q9): CommitteeType (unseeded — customer defines)
 * and CommitteeConfiguration (composition & voting rules). All rules are
 * configuration-driven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_hi');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('committee_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_type_id')->constrained('committee_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('min_members');
            $table->unsignedSmallInteger('max_members');
            $table->boolean('chairman_mandatory')->default(true);
            $table->unsignedSmallInteger('quorum');
            $table->string('voting_method', 60);
            $table->string('majority_type', 60);
            $table->string('tie_break_rule', 120);
            $table->boolean('digital_signature_required')->default(true);
            $table->boolean('remarks_required')->default(false);
            $table->boolean('external_member_allowed')->default(false);
            $table->boolean('secretary_required')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_configurations');
        Schema::dropIfExists('committee_types');
    }
};
