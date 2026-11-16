<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Secretary, CGPSC (business decision, 2026-11-14).
 *
 * After the Administrative Head approves a DPC it waits for the Secretary, who
 * approves it with an eSign — then it is forwarded to the Chairman — or returns it
 * to the Authorized Officer with a reason and a supporting document.
 *
 *  - The role itself, so a deployment that does not re-run the roles seeder can
 *    still grant it.
 *  - Who approved as Secretary, when, and the signature, on the proposal; the AH's
 *    approval keeps its own `approved_by` / `approved_at` / `signature_id`.
 *  - The Secretary's supporting document on the revision it returned, beside the
 *    reason that revision already records, and who returned it (the stage).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('roles')->where('name', 'secretary')->where('guard_name', 'web')->exists();

        if (! $exists) {
            DB::table('roles')->insert(['name' => 'secretary', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->foreignId('secretary_approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('secretary_approved_at')->nullable()->after('secretary_approved_by');
            $table->foreignId('secretary_signature_id')->nullable()->after('secretary_approved_at')->constrained('signatures')->nullOnDelete();
        });

        Schema::table('dpc_proposal_revisions', function (Blueprint $table): void {
            $table->string('returned_stage', 30)->nullable()->after('return_reason');
            $table->string('return_document_path')->nullable()->after('returned_stage');
            $table->string('return_document_name')->nullable()->after('return_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposal_revisions', function (Blueprint $table): void {
            $table->dropColumn(['returned_stage', 'return_document_path', 'return_document_name']);
        });

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('secretary_signature_id');
            $table->dropConstrainedForeignId('secretary_approved_by');
            $table->dropColumn('secretary_approved_at');
        });

        // The role is left: users may hold it, and removing it would drop their grant.
    }
};
