<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Vacancy Master — the CGPSC Administrator's year-wise vacancy position for one
 * post of one department.
 *
 * ### Three tables, each for one reason
 *
 * `vacancy_positions` — one row per Department + Post + Vacancy Year + revision. A
 * revision is how a finalized position is corrected: the finalized row is never
 * edited, a new draft revision supersedes it when it is finalized in turn.
 *
 * `vacancy_position_working_strengths` — every working strength ever recorded for a
 * position, with its order and supporting document. Append-only, so "what was the
 * working strength, on whose order, before it was corrected" stays answerable. The
 * position points at the one in force.
 *
 * `vacancy_position_entries` — reason-wise vacancies (retirement, higher-post
 * promotion, deputation, …), each with its order, document, eligibility and the
 * Administrator's reconciliation against the opening position.
 *
 * ### Identity of a post
 *
 * A Department Post Mapping is effective-dated: a new Government Order closes one
 * row and opens another for the same post. The position is therefore keyed on the
 * department and the post's **name** (the chain), and `department_post_id` records
 * which mapping was in force on 1 January of the Vacancy Year — the one the
 * sanctioned strength is read from.
 *
 * Additive only. No existing table or row is changed apart from a nullable column
 * on `dpc_proposals`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_positions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('post_name');
            $table->foreignId('department_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->unsignedSmallInteger('vacancy_year');

            $table->unsignedInteger('revision')->default(1);
            // draft | finalized | superseded
            $table->string('status', 20);
            $table->foreignId('supersedes_id')->nullable()->constrained('vacancy_positions')->nullOnDelete();
            $table->text('revision_reason')->nullable();

            // The figures fixed at finalization. Null while a draft.
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('sanctioned_strength')->nullable();
            $table->foreignId('recruitment_rule_id')->nullable()->constrained('recruitment_rules')->nullOnDelete();
            $table->decimal('promotion_percentage', 5, 2)->nullable();
            $table->string('rounding_rule', 20)->nullable();
            $table->integer('opening_vacancy')->nullable();
            $table->integer('promotion_vacancy')->nullable();
            $table->integer('direct_vacancy')->nullable();
            $table->json('breakdown')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'post_name', 'vacancy_year', 'revision'], 'vacancy_positions_unique');
            $table->index(['department_id', 'vacancy_year', 'status'], 'vacancy_positions_dept_year');
        });

        Schema::create('vacancy_position_working_strengths', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vacancy_position_id')->constrained('vacancy_positions')->cascadeOnDelete();

            // As on 1 January of the Vacancy Year.
            $table->unsignedInteger('working_total');

            /*
             | How the working officers came into the post — the same three
             | occupancy kinds the Working Officers chart records per row
             | (`dpc_working_officers.source`). Needed to split the opening
             | vacancy between the promotion and direct-recruitment quotas;
             | nullable so a total can be recorded first, which leaves the quota
             | split incomplete rather than guessed.
             */
            $table->unsignedInteger('working_promotion')->nullable();
            $table->unsignedInteger('working_direct')->nullable();
            $table->unsignedInteger('working_deputation')->nullable();

            $table->boolean('exceeds_sanctioned_acknowledged')->default(false);

            $table->string('order_number', 120);
            $table->date('order_date');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);

            $table->text('remarks')->nullable();
            $table->text('correction_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->foreignId('working_strength_id')->nullable()->after('revision_reason')
                ->constrained('vacancy_position_working_strengths')->nullOnDelete();
        });

        Schema::create('vacancy_position_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vacancy_position_id')->constrained('vacancy_positions')->cascadeOnDelete();

            // existing | anticipated
            $table->string('kind', 20);
            // retirement | death | higher_post_promotion | deputation | other
            $table->string('reason', 40);
            // promotion | direct — the quota of the chair the vacancy frees
            $table->string('occupant_quota', 20)->nullable();
            $table->unsignedInteger('vacancy_count');
            $table->date('effective_date');

            $table->string('order_number', 120);
            $table->date('order_date');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('remarks')->nullable();

            /*
             | The Administrator's answer to "is this vacancy already inside the
             | opening position?" — counted_in_working | already_in_opening. Count-
             | only entries cannot be matched to officers, so the portal asks
             | rather than guesses.
             */
            $table->string('reconciliation', 30)->nullable();

            // Higher-post promotion.
            $table->foreignId('higher_post_id')->nullable()->constrained('department_posts')->nullOnDelete();
            $table->string('higher_dpc_reference', 60)->nullable();
            $table->foreignId('higher_dpc_proposal_id')->nullable()->constrained('dpc_proposals')->nullOnDelete();
            $table->string('promotion_order_number', 120)->nullable();
            $table->date('promotion_order_date')->nullable();

            // Deputation.
            $table->date('deputation_to')->nullable();
            $table->unsignedSmallInteger('deputation_expected_months')->nullable();
            $table->boolean('against_sanctioned_post')->nullable();
            $table->string('cadre_details')->nullable();

            // eligible | pending_verification | not_eligible | informational
            $table->string('eligibility', 30);
            $table->text('eligibility_note')->nullable();
            $table->string('verification_basis', 40)->nullable();
            $table->text('verification_remarks')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // active | withdrawn
            $table->string('status', 20)->default('active');
            $table->text('withdrawal_reason')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vacancy_position_id', 'status'], 'vacancy_entries_position_status');
        });

        Schema::table('dpc_proposals', function (Blueprint $table): void {
            // The finalized Vacancy Master position the proposal was submitted against.
            $table->foreignId('vacancy_position_id')->nullable()
                ->constrained('vacancy_positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dpc_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vacancy_position_id');
        });

        Schema::table('vacancy_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('working_strength_id');
        });

        Schema::dropIfExists('vacancy_position_entries');
        Schema::dropIfExists('vacancy_position_working_strengths');
        Schema::dropIfExists('vacancy_positions');
    }
};
