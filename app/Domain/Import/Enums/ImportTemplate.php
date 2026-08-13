<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

use App\Domain\Dpc\Validation\DpcRowRules;

/**
 * The three Excel upload templates the PPT defines (S16–S18).
 *
 * Each case knows three things and nothing else: what its column headers
 * mean, which per-row rule set validates it, and which array key the
 * DpcProposalService save method expects. Validation rules themselves live in
 * DpcRowRules, shared with manual entry — this enum maps a spreadsheet onto
 * them, it does not restate them.
 */
enum ImportTemplate: string
{
    case CurrentlyWorkingUs = 'currently_working_us';
    case FinalSeniorityListUs = 'final_seniority_list_us';
    case EvaluationChartUs = 'evaluation_chart_us';

    public function label(): string
    {
        return match ($this) {
            self::CurrentlyWorkingUs => 'Currently Working Officers (S16)',
            self::FinalSeniorityListUs => 'Final Seniority List (S17)',
            self::EvaluationChartUs => 'Evaluation Chart (S18)',
        };
    }

    /**
     * Route name of the section screen this template feeds, used to send the
     * officer back where they came from.
     */
    public function sectionRoute(): string
    {
        return match ($this) {
            self::CurrentlyWorkingUs => 'dpc-proposals.working-officers',
            self::FinalSeniorityListUs => 'dpc-proposals.seniority-list',
            self::EvaluationChartUs => 'dpc-proposals.evaluation-chart',
        };
    }

    /**
     * Per-row validation rules, shared with the manual-entry Form Requests.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rowRules(): array
    {
        return match ($this) {
            self::CurrentlyWorkingUs => DpcRowRules::workingOfficer(),
            self::FinalSeniorityListUs => DpcRowRules::seniorityEntry(),
            self::EvaluationChartUs => DpcRowRules::evaluationRow(),
        };
    }

    /**
     * Whether this template carries the S18 per-year CR / loyalty / property
     * columns, which are parsed by pattern rather than by fixed header.
     */
    public function hasYearColumns(): bool
    {
        return $this === self::EvaluationChartUs;
    }

    /**
     * Accepted spreadsheet headers, normalised (lowercased, runs of
     * non-alphanumerics collapsed to `_`), mapped onto the field name the
     * service layer expects.
     *
     * Both the PPT's own Hindi-context English headers ("NAME OF US", "DATE
     * OF POSTING ON US", "CAT") and our own field names are accepted, so a
     * file exported from this system re-imports without editing.
     *
     * `reservation_category` is deliberately a *code* (UR/SC/ST/OBC) rather
     * than an id — a department filling in a spreadsheet has no access to our
     * primary keys. It is resolved against the M2 master during parsing.
     *
     * @return array<string, string>
     */
    public function columnMap(): array
    {
        $shared = [
            'employee_no' => 'employee_number',
            'employee_number' => 'employee_number',
            'emp_no' => 'employee_number',
            'name' => 'name',
            'name_of_us' => 'name',
            'name_of_officer' => 'name',
            'officer_name' => 'name',
            'dob' => 'dob',
            'date_of_birth' => 'dob',
            'qual' => 'qualification',
            'qualification' => 'qualification',
            'cat' => 'reservation_category',
            'category' => 'reservation_category',
            'reservation_category' => 'reservation_category',
            'current_post' => 'current_post',
            'current_designation' => 'current_post',
            'feeder_post' => 'feeder_post',
            'feeder_designation' => 'feeder_post',
            'date_of_joining_in_service' => 'date_of_joining_service',
            'date_of_joining_service' => 'date_of_joining_service',
            'date_of_joining' => 'date_of_joining_service',
            'date_of_posting_on_feeder_post' => 'date_of_posting_on_feeder_post',
        ];

        return match ($this) {
            self::CurrentlyWorkingUs => $shared + [
                'sn' => 'sn',
                's_n' => 'sn',
                'serial_no' => 'sn',
                'date_of_posting_on_post' => 'date_of_posting_on_post',
                'date_of_posting_on_current_post' => 'date_of_posting_on_post',
                'date_of_posting' => 'date_of_posting_on_post',
                'dop' => 'date_of_posting_on_post',
                'roster_position' => 'roster_position',
                'roster' => 'roster_position',
                'deputation_date' => 'deputation_date',
            ],

            self::FinalSeniorityListUs => $shared + [
                'sn' => 'seniority_number',
                's_n' => 'seniority_number',
                'serial_no' => 'seniority_number',
                'seniority_number' => 'seniority_number',
                'date_of_posting_on_current_post' => 'date_of_posting_on_current_post',
                // S17's "DATE OF POSTING ON US" is the feeder post, and its
                // values match S18's DOP column exactly.
                'date_of_posting_on_us' => 'date_of_posting_on_feeder_post',
                'date_of_posting' => 'date_of_posting_on_feeder_post',
                'dop' => 'date_of_posting_on_feeder_post',
            ],

            self::EvaluationChartUs => $shared + [
                'sn' => 'sn',
                's_n' => 'sn',
                'serial_no' => 'sn',
                // S18's DOP column carries the feeder-post posting date.
                'dop' => 'date_of_posting_on_feeder_post',
                'date_of_posting' => 'date_of_posting_on_feeder_post',
                'date_of_posting_on_current_post' => 'date_of_posting_on_current_post',
                'note' => 'note',
                'tribal_area_service_years' => 'tribal_area_service_years',
                'tribal' => 'tribal_area_service_years',
            ],
        };
    }

    /**
     * Array key the matching DpcProposalService save method expects.
     */
    public function payloadKey(): string
    {
        return match ($this) {
            self::CurrentlyWorkingUs => 'officers',
            self::FinalSeniorityListUs => 'entries',
            self::EvaluationChartUs => 'rows',
        };
    }

    /**
     * Column headers shown to the officer as the expected template.
     *
     * @return list<string>
     */
    public function expectedHeaders(): array
    {
        return match ($this) {
            self::CurrentlyWorkingUs => ['SN', 'EMPLOYEE NO', 'NAME', 'DOB', 'QUAL', 'CURRENT POST', 'FEEDER POST', 'CAT', 'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON POST', 'DATE OF POSTING ON FEEDER POST', 'ROSTER POSITION', 'DEPUTATION DATE'],
            self::FinalSeniorityListUs => ['SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'CURRENT POST', 'FEEDER POST', 'CAT', 'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON CURRENT POST', 'DATE OF POSTING ON US'],
            self::EvaluationChartUs => ['SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'DOP', 'CAT', 'LOYALTY 2013 …', 'PROPERTY 2013 …', 'CR 2012 …', 'TRIBAL AREA SERVICE YEARS', 'NOTE'],
        };
    }
}
