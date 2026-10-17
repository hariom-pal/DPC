<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Return the portal to a fresh installation **with respect to workflow data**,
 * keeping every master record and every user account.
 *
 * **The dangerous half of this command is the list, not the code.** Getting a
 * table into the wrong group destroys either a department's masters or the
 * evidence of a concluded DPC, so the two lists are exhaustive and explicit:
 * every table in the schema appears in exactly one of them, and
 * {@see verifyEveryTableIsClassified()} refuses to run if a table exists that
 * neither list names. A table added later therefore stops this command rather
 * than being silently kept or silently destroyed.
 *
 * **It refuses in production, and not politely.** These tables include the audit
 * trail, the workflow ledger and the signed-document register — the records the
 * portal exists to be able to produce in court. There is no flag that makes this
 * run in production, because there is no correct reason to.
 *
 * **Users are never touched.** Accounts, roles, permissions and department
 * assignments all sit in the preserved list, including the Promotion Candidate
 * accounts provisioned from Seniority Lists: candidature is *derived* from the
 * lists rather than stored, so clearing the lists leaves those accounts with no
 * candidature, which is exactly the fresh state. They pick their candidature back
 * up when a new list names their employee number.
 */
class ResetTransactionalDataCommand extends Command
{
    protected $signature = 'dpc:reset-transactional-data
        {--dry-run : List what would be cleared and change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear all Pre-DPC/DPC workflow data, keeping every master record and user account';

    /**
     * Everything the DPC process writes as it runs. Ordered child-before-parent so
     * the delete is legible; foreign keys are suspended anyway, because several of
     * these reference each other in both directions.
     *
     * @var list<string>
     */
    private const TRANSACTIONAL = [
        // Import staging.
        'import_rows',
        'import_batches',

        // Evaluation chart and its per-year children.
        'dpc_evaluation_cr_grades',
        'dpc_evaluation_loyalty_flags',
        'dpc_evaluation_property_flags',

        /*
         | Why a record was missing, and the PDF that evidenced it (change register
         | of 2026-08-27, items 14 to 19).
         |
         | Listed explicitly even though the foreign key cascades from
         | `dpc_evaluation_rows`, because this command **truncates** and a truncate
         | does not fire a cascade. Left out, these rows would survive the chart they
         | describe and point at officers who no longer exist.
         */
        'dpc_evaluation_record_gaps',

        // Employee-wise documents (CR, IPR, PH undertaking …). Before the rows and
        // the proposals they reference.
        'dpc_evaluation_documents',

        'dpc_evaluation_rows',

        // The rest of a proposal.
        // Rule 1.1 confirmations, with the offline-minutes evidence they cite.
        'dpc_proposal_verifications',
        // Vacancy Master: year-wise positions, their working strengths and entries.
        // Transactional because entries cite DPC proposals, which go too.
        'vacancy_position_entries',
        'vacancy_position_working_strengths',
        'vacancy_positions',
        'dpc_seniority_entries',
        'dpc_working_officers',
        'dpc_vacancies',
        'dpc_committee_members',
        'dpc_proposal_review_reasons',
        'dpc_proposal_revisions',

        // Engine output.
        'dpc_calculation_results',
        'dpc_calculation_runs',

        // The vacancy calculation (change register of 2026-08-27, item 10).
        // Evidence, and therefore transactional: it records what the engine
        // produced for one proposal, and a fresh installation has produced none.
        'dpc_vacancy_calculations',

        // Circulation.
        'dpc_circulation_documents',
        'dpc_circulation_items',
        'dpc_circulations',

        // Review chain.
        'dpc_document_review_references',
        'dpc_review_calculations',
        'dpc_review_links',

        // The sitting.
        //
        // `dpc_candidate_calls` records who the committee called and concluded at a
        // sitting — as transactional as the sitting itself, and meaningless without
        // the proceeding it hangs off.
        'dpc_candidate_calls',
        'dpc_proceeding_documents',
        'dpc_proceeding_holds',
        'dpc_proceeding_overrides',
        'dpc_proceedings',

        // The Commission's queue.
        'dpc_schedules',
        'dpc_allotments',

        /*
         | The Pre-DPC's own children. A sanctioning order is transactional — it is
         | raised, approved and superseded as part of the workflow — so its
         | promotion sources and their qualification links go with it.
         |
         | The `qualifications` **master** is preserved; this is only the join that
         | records which of them one order's feeder accepted.
         */
        'pre_dpc_source_qualifications',
        'pre_dpc_promotion_sources',

        // The proposals themselves.
        'dpc_proposals',
        'pre_dpc_proposals',

        // Cross-cutting records of what happened.
        'workflow_transitions',
        'activity_timelines',
        'signatures',
        'audits',
        'user_notifications',
        'self_inspection_accesses',

        // Issued numbers: a fresh installation starts at 0001 again.
        'dpc_id_sequences',

        // Transient authentication and queue state. Not DPC data, but a reset that
        // left a half-finished OTP or a queued notification behind would produce
        // behaviour nobody could explain.
        'otp_verifications',
        'password_reset_tokens',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    /**
     * Master and configuration data, and the people. Never touched.
     *
     * @var list<string>
     */
    private const PRESERVED = [
        // People and access.
        'users',
        'user_department_assignments',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',

        // Masters.
        'departments',
        'department_posts',

        /*
         | The reasons a mandatory record may be unavailable (change register of
         | 2026-08-27, item 16).
         |
         | A **master** the Administrator maintains, and the register of 2026-08-26
         | item 1 is explicit that no master data is cleared. Clearing it would also
         | break the restore: the reasons are seeded once and referenced by every gap
         | recorded afterwards.
         */
        'record_reasons',

        /*
         | The Government Orders behind a Department Post Mapping, and their PDFs
         | (change register of 2026-08-26, item 10). **Master data**, and the
         | register's "do not delete" list names Department Post Mapping explicitly
         | — an order is the legal reference that makes a mapping lawful, so
         | clearing it would leave the master citing nothing.
         */
        'department_post_orders',
        'pay_scales',
        'service_classes',
        'designations',
        'districts',
        'qualifications',
        'reservation_categories',
        'reservation_policies',
        'reservation_rosters',
        'reservation_roster_points',
        'committee_types',
        'committee_configurations',

        /*
         | Who the CGPSC Chairman is, and was (item 9). Administered rather than
         | transactional: clearing it would leave the portal unable to compose a
         | single committee, because item 9 makes the seat mandatory and
         | `DpcCommitteeService` refuses without a Chairman on record.
         */
        'cgpsc_chairmen',

        'recruitment_rules',
        'recruitment_rule_sources',

        // Which qualifications a recruitment rule's source accepts (item 11) —
        // part of the rule, and the rule is a master.
        'rr_source_qualifications',
        'review_reasons',
        'vacancy_types',
        'dpc_types',
        'portal_notices',

        // Framework.
        'migrations',
    ];

    /**
     * Generated and uploaded files, which are as transactional as the rows that
     * point at them.
     *
     * @var list<string>
     */
    private const STORAGE_PATHS = [
        'private/proceedings',
        'private/circulations',
        'private/signatures',
        'private/imports',

        // The supporting documents behind a missing record (item 19). Uploaded as
        // part of a DPC, so they go when the DPC's data goes.
        'private/record-gaps',
        'private/employee-documents',

        // Rule 1.1 previous-DPC minutes, and Vacancy Master orders.
        'private/proposal-verifications',
        'private/vacancy-master',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: this clears the audit trail, the workflow ledger and the signed-document register.');
            $this->line('There is no flag that makes it run in production.');

            return self::FAILURE;
        }

        if (($unclassified = $this->verifyEveryTableIsClassified()) !== []) {
            $this->error('Refused: '.count($unclassified).' table(s) are in neither list, so this command cannot say whether they hold workflow data:');

            foreach ($unclassified as $table) {
                $this->line("  - {$table}");
            }

            $this->line('Add each to TRANSACTIONAL or PRESERVED in '.class_basename($this).'.');

            return self::FAILURE;
        }

        $counts = $this->counts();
        $total = array_sum($counts);

        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn (int $rows, string $table): array => [$table, number_format($rows)])->values()->all(),
        );

        $files = $this->storageFiles();

        $this->newLine();
        $this->line(sprintf('%s row(s) across %d table(s), and %d stored file(s).', number_format($total), count($counts), count($files)));
        $this->line(sprintf('%d master and access table(s) will not be touched.', count(self::PRESERVED)));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Clear all of it?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->clearTables();
        $this->clearStorage($files);

        $this->newLine();
        $this->info(sprintf('Cleared %s row(s) and %d file(s).', number_format($total), count($files)));
        $this->line('Masters, users, roles and department assignments are unchanged.');

        return self::SUCCESS;
    }

    /**
     * Every table in the schema that neither list names.
     *
     * @return list<string>
     */
    private function verifyEveryTableIsClassified(): array
    {
        $known = array_merge(self::TRANSACTIONAL, self::PRESERVED);

        return array_values(array_diff($this->tables(), $known));
    }

    /**
     * Every table in *this* database.
     *
     * Through the schema builder rather than `SHOW TABLES`, which is MySQL-only:
     * the suite runs on SQLite, so the classification check — the most important
     * guard in this command — would have been the one thing never exercised.
     *
     * Scoped to the current database and unqualified, because on a MySQL server
     * hosting several applications the unscoped listing returns every schema's
     * tables and the check would report hundreds of "unclassified" tables that are
     * nothing to do with this portal.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return Schema::getTableListing(
            schema: DB::connection()->getDatabaseName(),
            schemaQualified: false,
        );
    }

    /**
     * Row counts for the tables that exist and hold something.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TRANSACTIONAL as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                $counts[$table] = $rows;
            }
        }

        return $counts;
    }

    private function clearTables(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TRANSACTIONAL as $table) {
                if (Schema::hasTable($table)) {
                    // Truncate rather than delete: it resets AUTO_INCREMENT, and a
                    // fresh installation numbering its first proposal `#1` is the
                    // whole point of the exercise.
                    DB::table($table)->truncate();
                }
            }
        } finally {
            // In a `finally` because leaving constraints disabled on a connection
            // that is about to be reused is worse than the failure that caused it.
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @return list<string>
     */
    private function storageFiles(): array
    {
        $files = [];

        foreach (self::STORAGE_PATHS as $path) {
            $directory = storage_path('app/'.$path);

            if (File::isDirectory($directory)) {
                foreach (File::allFiles($directory) as $file) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $files
     */
    private function clearStorage(array $files): void
    {
        foreach (self::STORAGE_PATHS as $path) {
            $directory = storage_path('app/'.$path);

            if (File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        $this->line(sprintf('Removed %d generated and uploaded file(s).', count($files)));
    }
}
