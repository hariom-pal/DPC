<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generator commit hash on every document (architect Directive 4, post-Phase 4).
 *
 * `generator_version` says *which renderer* produced a document — `html-v1`.
 * That is a semantic label, and a semantic label is only as honest as the
 * person who remembered to bump it: two builds can both call themselves
 * `html-v1` and render differently.
 *
 * The commit hash says *which build*, exactly, with nothing to remember. For a
 * document that may be produced in evidence years later, "rendered by html-v1"
 * is an assertion; "rendered by commit `a1b2c3…`" is a fact that can be
 * checked out and re-run.
 *
 * Nullable because the value comes from `APP_GIT_COMMIT`, which is set at
 * build time and is legitimately absent in a development checkout — recording
 * `unknown` honestly is better than refusing to generate a document.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpc_proceeding_documents', function (Blueprint $table) {
            $table->string('generator_commit_hash', 64)->nullable()->after('generator_version');
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proceeding_documents', function (Blueprint $table) {
            $table->dropColumn('generator_commit_hash');
        });
    }
};
