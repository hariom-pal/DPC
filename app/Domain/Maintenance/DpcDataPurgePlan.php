<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * What `dpc:purge-dpc-data` (and Admin → Clear DPC data) removes: every Pre-DPC and
 * DPC record and nothing else (2026-11-02).
 *
 * Unlike `dpc:uat-cleanup` it keeps **every** account, session, security event,
 * identity record, invitation and master, and the employee database. The tables shared with the rest of the
 * portal — the workflow ledger, signatures, the audit trail, the activity timeline and
 * notifications — lose only the rows whose subject is one of the records below.
 *
 * **The Recruitment Rule goes with the Pre-DPC.** Since the Administrator's RR screen
 * was removed (2026-10-17) a rule is only ever written by approving a Pre-DPC
 * (`PreDpcProposalService::projectToMasters`), so a rule left behind would be a
 * Pre-DPC's result with no Pre-DPC.
 */
final class DpcDataPurgePlan
{
    /**
     * Emptied completely. Each entry: table => what it holds.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        // Pre-DPC.
        'pre_dpc_proposals' => 'Pre-DPC proposals',
        'pre_dpc_promotion_sources' => 'Pre-DPC feeder posts',
        'pre_dpc_source_qualifications' => 'Pre-DPC feeder qualifications',
        'recruitment_rules' => 'Recruitment rules written by approved Pre-DPCs',
        'recruitment_rule_sources' => 'Their feeder posts',
        'rr_source_qualifications' => 'Their feeder qualifications',
        'proposal_allotments' => 'Pre-DPC / DPC allotments to an AO and an AH',

        // Vacancy Management of each Pre-DPC.
        'vacancy_positions' => 'Vacancy Management records',
        'vacancy_position_sanctioned_strengths' => 'Sanctioned strengths',
        'vacancy_position_working_strengths' => 'Working strengths',
        'vacancy_position_entries' => 'Vacancies during the year (B1, B2, C)',

        // DPC proposal.
        'dpc_proposals' => 'DPC proposals',
        'dpc_proposal_revisions' => 'Proposal revision history',
        'dpc_proposal_review_reasons' => 'Review DPC reasons',
        'dpc_proposal_verifications' => 'Rule 1.1 confirmations',
        'dpc_proposal_representations' => 'Representations and complaints',
        'previous_dpc_records' => 'Offline previous DPCs (rule 1.1)',
        'dpc_vacancies' => 'Vacancy breakdowns',
        'dpc_vacancy_calculations' => 'Vacancy calculations',
        'dpc_committee_members' => 'Committee composition',
        'dpc_seniority_entries' => 'Seniority lists',
        'dpc_working_officers' => 'Working officers lists',
        'import_batches' => 'Excel uploads',
        'import_rows' => 'Excel upload rows',

        // Evaluation chart.
        'dpc_evaluation_rows' => 'Employee rows',
        'dpc_evaluation_cr_grades' => 'CR grades per year',
        'dpc_evaluation_loyalty_flags' => 'Loyalty per year',
        'dpc_evaluation_property_flags' => 'Property returns per year',
        'dpc_evaluation_record_gaps' => 'Missing-record justifications',
        'dpc_evaluation_documents' => 'Employee-wise documents',
        'self_inspection_accesses' => 'Self-inspection access log',

        // Calculation, Commission, sitting and after.
        'dpc_calculation_runs' => 'Calculation runs',
        'dpc_calculation_results' => 'Calculation results',
        'dpc_allotments' => 'Allotments to members',
        'dpc_schedules' => 'Sitting schedules',
        'dpc_proceedings' => 'Sittings',
        'dpc_candidate_calls' => 'Candidates called',
        'dpc_proceeding_holds' => 'Promotion holds',
        'dpc_proceeding_overrides' => 'Committee corrections',
        'dpc_proceeding_documents' => 'Minutes and promotion lists',
        'dpc_document_review_references' => 'Review DPC document references',
        'dpc_promotion_orders' => 'Promotion orders',
        'dpc_circulations' => 'Circulations',
        'dpc_circulation_items' => 'Circulation decisions',
        'dpc_circulation_documents' => 'Circulation documents',
        'dpc_review_links' => 'Review DPC chains',
        'dpc_review_calculations' => 'Review DPC calculations',

        // Issued numbers: the next Pre-DPC / DPC starts at 0001 again.
        'dpc_id_sequences' => 'Pre-DPC / DPC number series',
    ];

    /**
     * Shared tables: only rows whose subject is a record of a table above go.
     * Each entry: table => its morph type column.
     *
     * @var array<string, string>
     */
    public const SHARED = [
        'workflow_transitions' => 'subject_type',
        'activity_timelines' => 'subject_type',
        'audits' => 'auditable_type',
        'user_notifications' => 'subject_type',
        'signature_attempts' => 'signable_type',
        'signatures' => 'signable_type',
    ];

    /**
     * Every column in the schema that names a stored file: table => list of
     * [disk column or `config:` key, path column]. Files of the purged rows are
     * collected from here; afterwards a file still named by ANY remaining row is kept.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const FILE_REFERENCES = [
        'pre_dpc_proposals' => [['rules_document_disk', 'rules_document_path']],
        'recruitment_rules' => [['go_document_disk', 'go_document_path']],
        'proposal_allotments' => [['order_disk', 'order_path']],
        'vacancy_position_entries' => [['disk', 'path']],
        'vacancy_position_working_strengths' => [['disk', 'path']],
        'vacancy_position_sanctioned_strengths' => [['disk', 'path']],
        'dpc_proposal_verifications' => [['disk', 'path']],
        'dpc_proposal_representations' => [['disk', 'path']],
        'previous_dpc_records' => [['minutes_disk', 'minutes_path'], ['seniority_list_disk', 'seniority_list_path'], ['working_officers_disk', 'working_officers_path']],
        'import_batches' => [['config:dpc.imports.disk', 'stored_path'], ['config:dpc.imports.disk', 'error_report_path']],
        'dpc_evaluation_record_gaps' => [['disk', 'path'], ['second_disk', 'second_path']],
        'dpc_evaluation_documents' => [['disk', 'path']],
        'dpc_proceeding_documents' => [['disk', 'path']],
        'dpc_promotion_orders' => [['disk', 'path']],
        'dpc_circulation_documents' => [['disk', 'path']],
        'signatures' => [['signed_disk', 'signed_path']],

        // Kept, and consulted so a file they share is never deleted.
        'department_post_orders' => [['disk', 'path']],
        'reservation_policies' => [['disk', 'path']],
        'identity_verifications' => [['document_disk', 'document_path']],
        'identity_corrections' => [['document_disk', 'document_path']],
        'assignment_requests' => [['document_disk', 'document_path']],
    ];
}
