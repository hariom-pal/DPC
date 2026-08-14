<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Held promotions (`CIR`) and generated documents — M7 Phase 4.
 *
 * **`dpc_proceeding_holds`** records officers included in the DPC whose
 * promotion action is reserved (PPT S45, C3). A hold is orthogonal to merit:
 * S33's US8 scores 20, qualifies for a Super Seat, and is held anyway because
 * its 2016 immovable-property return is missing. That is why it lives here
 * rather than inside the frozen engine (architect ruling AQ-4). `released_at`
 * is the declared seam for M9, which *resolves* a hold; M7 only marks it.
 *
 * **`dpc_proceeding_documents`** records the proceedings document and the
 * promotion list. Each carries its own UUID, a document version, a generator
 * version and a generation timestamp (architect Directive 3, Phase 1), plus the
 * three permanent references an approved result must carry (Directive 2,
 * Phase 4): the proceeding, the final calculation, and the proposal.
 *
 * **`dpc_proposals.uuid`** is added and backfilled. The standing rule is that
 * the UUID is the only public identifier, and a legal document that referenced
 * a proposal by sequential id would leak how many proposals exist. Following
 * the M6 precedent that added `users.uuid`: `id` remains the primary key and
 * the route key, so no relation and no URL changes. **No M4 source file is
 * modified** — the column is backfilled here and minted for later proposals by
 * `ProceedingService`, because adding `HasUuids` to the frozen `DpcProposal`
 * model would be a change to an approved, frozen milestone.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Row-by-row: MySQL and SQLite share no UUID function (TD-3). Volumes
        // are trivial here.
        foreach (DB::table('dpc_proposals')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('dpc_proposals')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('dpc_proposals', function (Blueprint $table) {
            $table->unique('uuid');
        });

        Schema::table('dpc_proceedings', function (Blueprint $table) {
            // Architect Directive 1 (Phase 4). Which build of the scoring
            // engine produced the calculation currently in force. See the
            // review: the run row itself cannot carry this without editing
            // frozen M5 code, so it is recorded here, on every calculate
            // event, and stamped into every document.
            $table->string('calculation_engine_version', 50)->nullable()->after('calculated_through_sequence');
        });

        Schema::create('dpc_proceeding_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();
            $table->foreignId('dpc_evaluation_row_id')->nullable()
                ->constrained('dpc_evaluation_rows')->nullOnDelete();

            // Snapshots, so the record stays readable if the source row goes.
            $table->unsignedSmallInteger('sn');
            $table->string('officer_name');

            $table->string('reason_code', 30);
            $table->string('detail')->nullable();

            // Whether the rule found it or the Commission recorded it by hand.
            // The distinction matters: an automatic hold is reproducible from
            // the chart, a manual one is somebody's decision.
            $table->boolean('is_automatic')->default(true);

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name');
            $table->timestamp('recorded_at');

            // M9 writes this. Declared now so M9 is purely additive.
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->unique(['dpc_proceeding_id', 'sn', 'reason_code'], 'dpc_hold_proceeding_sn_reason_unique');
            $table->index(['dpc_proceeding_id', 'released_at']);
        });

        Schema::create('dpc_proceeding_documents', function (Blueprint $table) {
            $table->id();

            // Directive 3 (Phase 1) — document UUID.
            $table->uuid('uuid')->unique();

            $table->foreignId('dpc_proceeding_id')->constrained('dpc_proceedings')->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('status', 20)->default('pending');

            // Directive 2 (Phase 4) — the three permanent references. Stored as
            // UUID strings rather than foreign keys: a legal document must
            // remain readable and verifiable even if a row it points at is
            // later archived, and a UUID does not depend on a join surviving.
            $table->uuid('proceeding_uuid');
            $table->uuid('final_calculation_uuid')->nullable();
            $table->uuid('proposal_uuid');

            // Directive 3 (Phase 1) — version and provenance.
            $table->unsignedInteger('document_version')->default(1);
            $table->string('generator_version', 50);
            $table->string('calculation_engine_version', 50)->nullable();
            $table->string('rule_version', 50)->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->char('content_hash', 64)->nullable();

            $table->foreignId('signature_id')->nullable()->constrained('signatures')->nullOnDelete();

            // Directive 5 — a provisional result is never published. The
            // document may exist; it is not released to the department.
            $table->timestamp('published_at')->nullable();

            $table->text('failure_reason')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('generated_by_name')->nullable();

            $table->timestamps();

            $table->index(['dpc_proceeding_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpc_proceeding_documents');
        Schema::dropIfExists('dpc_proceeding_holds');

        Schema::table('dpc_proceedings', function (Blueprint $table) {
            $table->dropColumn('calculation_engine_version');
        });

        Schema::table('dpc_proposals', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
