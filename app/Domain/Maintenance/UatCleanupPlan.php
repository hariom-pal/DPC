<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * Which tables `dpc:uat-cleanup` empties, which it trims, and which it never touches.
 *
 * Built from the classification `dpc:reset-transactional-data` already maintains, with
 * one difference that is the whole reason this command exists: **user accounts are
 * cleaned too**, except the CGPSC Administrators. Everything else a Department or the
 * Commission needs to start a DPC from nothing — departments, posts, their orders,
 * recruitment rules, every master and the roles themselves — is preserved.
 *
 * A table in the schema that is in none of the three lists is **preserved** and
 * reported: an unknown table is never destroyed by guesswork.
 */
final class UatCleanupPlan
{
    /**
     * Emptied completely. Each entry: table => why.
     *
     * @var array<string, string>
     */
    public const CLEAN = [
        // Import staging.
        'import_rows' => 'Excel import staging rows',
        'import_batches' => 'Excel import batches (and their stored files)',

        // The evaluation chart and its per-year children — employee snapshots.
        'dpc_evaluation_cr_grades' => 'Employee CR grades per year (DPC evaluation chart)',
        'dpc_evaluation_loyalty_flags' => 'Employee loyalty flags per year',
        'dpc_evaluation_property_flags' => 'Employee property-return flags per year',
        'dpc_evaluation_record_gaps' => 'Missing-record justifications and their PDFs',
        'dpc_evaluation_documents' => 'Employee-wise documents: CR, IPR, PH undertaking (and their PDFs)',
        'dpc_evaluation_rows' => 'Employee rows of the evaluation chart',

        // The rest of a DPC proposal.
        'dpc_proposal_verifications' => 'Rule 1.1 confirmations and previous-DPC minutes',
        'vacancy_position_entries' => 'Vacancy Master reason-wise entries (test data)',
        'vacancy_position_working_strengths' => 'Vacancy Master working strengths (test data)',
        'vacancy_position_sanctioned_strengths' => 'Vacancy Master year-wise sanctioned strengths (test data)',
        'vacancy_positions' => 'Vacancy Master positions (test data)',
        'dpc_seniority_entries' => 'Employee seniority-list snapshots',
        'dpc_working_officers' => 'Employee working-officer snapshots (service records)',
        'dpc_vacancies' => 'DPC vacancy breakdowns',
        'dpc_committee_members' => 'DPC committee composition',
        'dpc_proposal_review_reasons' => 'Review DPC reasons cited by proposals',
        'dpc_proposal_revisions' => 'DPC proposal revision history',
        'dpc_calculation_results' => 'Eligibility calculation results (recommendations)',
        'dpc_calculation_runs' => 'Eligibility calculation runs',
        'dpc_vacancy_calculations' => 'Recorded vacancy calculations',
        'dpc_circulation_documents' => 'Circulation documents (generated files)',
        'dpc_circulation_items' => 'Circulation decisions',
        'dpc_circulations' => 'Circulations',
        'dpc_document_review_references' => 'Review DPC document references',
        'dpc_review_calculations' => 'Review DPC calculation provenance',
        'dpc_review_links' => 'Review DPC chains',
        'dpc_candidate_calls' => 'Candidates called at sittings',
        'dpc_proceeding_documents' => 'Proceedings and promotion lists (minutes and decisions)',
        'dpc_proceeding_holds' => 'Promotion holds',
        'dpc_proceeding_overrides' => 'Committee corrections at sittings',
        'dpc_proceedings' => 'DPC sittings',
        'dpc_schedules' => 'Sitting schedules',
        'dpc_allotments' => 'DPC allotments to members',
        'pre_dpc_source_qualifications' => 'Pre-DPC feeder qualifications',
        'pre_dpc_promotion_sources' => 'Pre-DPC feeder posts',
        'dpc_proposals' => 'DPC proposals (cases)',
        'pre_dpc_proposals' => 'Pre-DPC proposals',

        // Cross-cutting records of what happened.
        'workflow_transitions' => 'Workflow ledger (transactions)',
        'activity_timelines' => 'Activity timeline (workflow history)',
        'signatures' => 'Digital signatures (and their stored copies)',
        'audits' => 'Model audit trail of the test period',
        'user_notifications' => 'In-app notifications',
        'self_inspection_accesses' => 'Self-inspection access log',
        'dpc_id_sequences' => 'Issued DPC / Pre-DPC numbers, so numbering restarts',

        // Transient authentication and queue state.
        'otp_verifications' => 'OTP records',
        'password_reset_tokens' => 'Password reset tokens',
        'sessions' => 'Database sessions (everybody is logged out)',
        'jobs' => 'Queued jobs',
        'job_batches' => 'Queued job batches',
        'failed_jobs' => 'Failed jobs',
        'cache' => 'Database cache entries',
        'cache_locks' => 'Database cache locks',
    ];

    /**
     * Trimmed: only rows belonging to accounts that are not CGPSC Administrators are
     * removed. Each entry: table => why.
     *
     * @var array<string, string>
     */
    public const PARTIAL = [
        'users' => 'Every account except the CGPSC Administrators',
        'model_has_roles' => 'Role assignments of the deleted accounts',
        'model_has_permissions' => 'Direct permissions of the deleted accounts',
        'user_department_assignments' => 'Department postings of the deleted accounts',
    ];

    /**
     * Never touched. Each entry: table => why.
     *
     * @var array<string, string>
     */
    public const PRESERVE = [
        'roles' => 'Role definitions',
        'permissions' => 'Permission definitions',
        'role_has_permissions' => 'Role-permission grants',
        'departments' => 'Departments',
        'department_posts' => 'Posts (Department Post Mapping)',
        'department_post_orders' => 'Government orders behind each post mapping',
        'record_reasons' => 'Missing-record reasons master',
        'pay_scales' => 'Pay scale master',
        'service_classes' => 'Service class master',
        'designations' => 'Designation master',
        'districts' => 'District master',
        'qualifications' => 'Qualification master',
        'reservation_categories' => 'Reservation category master',
        'reservation_policies' => 'Reservation policy master',
        'reservation_rosters' => 'Reservation roster master',
        'reservation_roster_points' => 'Reservation roster points',
        'committee_configurations' => 'Committee configuration',
        'cr_scoring_scales' => 'DPC Rules: CR marks and qualifying scores (and their orders)',
        'cgpsc_chairmen' => 'CGPSC Chairman record (required to compose any committee)',
        'recruitment_rules' => 'Recruitment rules',
        'recruitment_rule_sources' => 'Recruitment rule feeder posts',
        'rr_source_qualifications' => 'Recruitment rule feeder qualifications',
        'review_reasons' => 'Review reason master',
        'vacancy_types' => 'Vacancy type master',
        'dpc_types' => 'DPC type master',
        'portal_notices' => 'Portal notices',
        'migrations' => 'Migration history',
    ];

    /**
     * Columns that point at stored files: table => list of [disk column or `config:` key, path column].
     * Tables in CLEAN are where files are collected for deletion; every table here is
     * consulted afterwards, so a file still referenced by any remaining row is kept.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const FILE_REFERENCES = [
        'dpc_circulation_documents' => [['disk', 'path']],
        'dpc_evaluation_record_gaps' => [['disk', 'path']],
        'dpc_evaluation_documents' => [['disk', 'path']],
        'dpc_proceeding_documents' => [['disk', 'path']],
        'dpc_proposal_verifications' => [['disk', 'path']],
        'vacancy_position_entries' => [['disk', 'path']],
        'vacancy_position_working_strengths' => [['disk', 'path']],
        'vacancy_position_sanctioned_strengths' => [['disk', 'path']],
        'signatures' => [['signed_disk', 'signed_path']],
        'import_batches' => [['config:dpc.imports.disk', 'stored_path'], ['config:dpc.imports.disk', 'error_report_path']],
        'department_post_orders' => [['disk', 'path']],
        'recruitment_rules' => [['go_document_disk', 'go_document_path']],
        'cr_scoring_scales' => [['disk', 'path']],
    ];

    /**
     * @return 'clean'|'partial'|'preserve'|'unknown'
     */
    public static function classify(string $table): string
    {
        return match (true) {
            array_key_exists($table, self::CLEAN) => 'clean',
            array_key_exists($table, self::PARTIAL) => 'partial',
            array_key_exists($table, self::PRESERVE) => 'preserve',
            default => 'unknown',
        };
    }

    public static function reason(string $table): string
    {
        return self::CLEAN[$table] ?? self::PARTIAL[$table] ?? self::PRESERVE[$table] ?? 'Not classified — preserved';
    }
}
