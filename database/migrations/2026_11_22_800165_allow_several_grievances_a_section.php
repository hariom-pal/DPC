<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several grievances a section (2026-11-22): a candidate with two CR years wrong
 * raises one grievance on each, not one for the whole section.
 *
 * The plain index is added before the unique one goes: on MySQL the participant's
 * foreign key needs an index that starts with its column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_grievances', function (Blueprint $table): void {
            $table->index(['dpc_verification_participant_id', 'section'], 'dpc_grievance_participant_section');
        });

        Schema::table('dpc_grievances', function (Blueprint $table): void {
            $table->dropUnique('dpc_grievance_one_per_section');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_grievances', function (Blueprint $table): void {
            $table->unique(['dpc_verification_participant_id', 'section'], 'dpc_grievance_one_per_section');
        });

        Schema::table('dpc_grievances', function (Blueprint $table): void {
            $table->dropIndex('dpc_grievance_participant_section');
        });
    }
};
