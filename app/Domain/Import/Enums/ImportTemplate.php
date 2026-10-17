<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

use App\Domain\Dpc\Validation\DpcRowRules;
use Illuminate\Support\Carbon;

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
     * The headers these columns carried until change item 6, and until when.
     *
     * **An alias with no end date is permanent.** Accepting last month's workbook
     * is right — a department that downloaded one before the rename is not making a
     * mistake, and refusing it would be a regression introduced by a renaming
     * exercise. But in two years nobody will know whether these four are still
     * load-bearing, and the way to find out will be to delete them and see what
     * breaks.
     *
     * So the cutoff is a date rather than a decision deferred forever. Until it
     * passes the old headers are read as before; after it they are simply unknown
     * columns, which the importer already ignores and reports.
     * `DPC_IMPORT_LEGACY_HEADERS_UNTIL` is unset by default, meaning **no cutoff has
     * been decided** — the date belongs to the Commission, and inventing one would
     * be exactly the kind of business rule this project does not invent.
     *
     * @return array<string, string>
     */
    private static function legacyAliases(): array
    {
        $until = config('dpc.imports.legacy_headers_until');

        if (is_string($until) && $until !== '' && now()->startOfDay()->gt(Carbon::parse($until)->endOfDay())) {
            return [];
        }

        return [
            'current_post' => 'promotion_to_post',
            'feeder_post' => 'promotion_from_post',
            'date_of_posting_on_current_post' => 'date_of_posting_on_promotion_to_post',
            'date_of_posting_on_feeder_post' => 'date_of_posting_on_promotion_from_post',
        ];
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
            /*
             | The officer's mobile number (business instruction, 2026-09-07: *"we
             | should have a mobile number for the candidate — it will help them log
             | in"*). In the shared block, so a `MOBILE` column means the same thing
             | on all three files.
             */
            'mobile' => 'mobile',
            'mobile_no' => 'mobile',
            'mobile_number' => 'mobile',
            'contact_no' => 'mobile',
            'phone' => 'mobile',

            'dob' => 'dob',
            'date_of_birth' => 'dob',
            'qual' => 'qualification',
            'qualification' => 'qualification',
            'cat' => 'reservation_category',
            'category' => 'reservation_category',
            'reservation_category' => 'reservation_category',

            /*
             | The **seat's** category, beside the officer's own (business
             | instruction, 2026-09-07). An SC officer selected on merit sits on a
             | General point: `CAT` is SC, this is UR.
             |
             | Several spellings, because departments name this column differently
             | and the one thing a template cannot fix is a file written before it
             | existed.
             */
            'seat_category' => 'seat_reservation_category',
            'seat_cat' => 'seat_reservation_category',
            'post_category' => 'seat_reservation_category',
            'roster_category' => 'seat_reservation_category',
            'appointed_against' => 'seat_reservation_category',
            'seat_reservation_category' => 'seat_reservation_category',
            'promotion_to_post' => 'promotion_to_post',
            'current_designation' => 'promotion_to_post',
            'promotion_from_post' => 'promotion_from_post',
            'feeder_designation' => 'promotion_from_post',
            'date_of_joining_in_service' => 'date_of_joining_service',
            'date_of_joining_service' => 'date_of_joining_service',
            'date_of_joining' => 'date_of_joining_service',
            'date_of_posting_on_promotion_from_post' => 'date_of_posting_on_promotion_from_post',

            /*
             | The headers these columns carried until 2026-08-24, kept as
             | aliases rather than deleted.
             |
             | Item 6 renamed "Current Post" to "Promotion To Post" and "Feeder
             | Post" to "Promotion From Post" — including on the blank templates
             | departments download. A department that downloaded a workbook last
             | week and uploads it next week is not making a mistake, and refusing
             | it would be a regression introduced by a renaming exercise. The
             | mapping is the same one the `*_designation` aliases directly above
             | already assert.
             */
            ...self::legacyAliases(),
        ];

        return match ($this) {
            self::CurrentlyWorkingUs => $shared + [
                'sn' => 'sn',
                's_n' => 'sn',
                'serial_no' => 'sn',
                'date_of_posting_on_promotion_to_post' => 'date_of_posting_on_promotion_to_post',
                // S16 headed this column "DATE OF POSTING ON POST" until
                // 2026-08-24 — which was also the column's own name, hence the
                // one alias `$shared` does not already cover.
                'date_of_posting_on_post' => 'date_of_posting_on_promotion_to_post',
                'date_of_posting' => 'date_of_posting_on_promotion_to_post',
                'dop' => 'date_of_posting_on_promotion_to_post',
                'roster_position' => 'roster_position',
                'roster' => 'roster_position',
                /*
                 | Change register of 2026-08-27, items 3 and 4.
                 |
                 | `deputation_date` is kept as an alias for `deputation_from`,
                 | because a department holding a half-filled spreadsheet from last
                 | month should not have its upload refused over a header — the same
                 | reasoning as the legacy aliases above.
                 */
                /*
                 | Whether the row is an officer or an empty chair (business
                 | directive, 2026-09-02). Absent means `working`, which is what
                 | every file written before the directive carries.
                 */
                'status' => 'status',
                'row_status' => 'status',
                'post_source' => 'source',
                'source' => 'source',
                'source_of_appointment' => 'source',
                'appointed_by' => 'source',
                'mode_of_appointment' => 'source',
                'post_status' => 'status',
                'working_vacant' => 'status',

                'date_of_retirement' => 'date_of_retirement',
                'retirement_date' => 'date_of_retirement',
                'dor' => 'date_of_retirement',
                'deputation_from' => 'deputation_from',
                'deputation_date' => 'deputation_from',
                'deputation_to' => 'deputation_to',
            ],

            /*
             | `array_merge`, not `+`, for this one: the simplified list's own
             | headers must win over the shared and legacy aliases. "CURRENT POST" is
             | the clearest case — the legacy alias reads it as the promotion-TO post
             | (its meaning before 2026-08-24), while on the simplified Seniority List
             | it is the post the officer holds now, the promotion-FROM post.
             */
            self::FinalSeniorityListUs => array_merge($shared, [
                'seniority_no' => 'seniority_number',
                'employee_id' => 'employee_number',
                'employee_code' => 'employee_number',
                'emp_id' => 'employee_number',
                'employee_name' => 'name',
                'date_of_appointment' => 'date_of_joining_service',
                'appointment_date' => 'date_of_joining_service',
                'current_post' => 'promotion_from_post',
                'date_of_posting_on_current_post' => 'date_of_posting_on_promotion_from_post',
                'sn' => 'seniority_number',
                's_n' => 'seniority_number',
                'serial_no' => 'seniority_number',
                'seniority_number' => 'seniority_number',
                'date_of_posting_on_promotion_to_post' => 'date_of_posting_on_promotion_to_post',
                // S17's "DATE OF POSTING ON US" is the promotion from post, and its
                // values match S18's DOP column exactly.
                'date_of_posting_on_us' => 'date_of_posting_on_promotion_from_post',
                'date_of_posting' => 'date_of_posting_on_promotion_from_post',
                'dop' => 'date_of_posting_on_promotion_from_post',
            ]),

            self::EvaluationChartUs => $shared + [
                'sn' => 'sn',
                's_n' => 'sn',
                'serial_no' => 'sn',
                // S18's DOP column carries the feeder-post posting date.
                'dop' => 'date_of_posting_on_promotion_from_post',
                'date_of_posting' => 'date_of_posting_on_promotion_from_post',
                'date_of_posting_on_promotion_to_post' => 'date_of_posting_on_promotion_to_post',
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
            self::CurrentlyWorkingUs => ['SN', 'STATUS', 'SOURCE', 'EMPLOYEE NO', 'NAME', 'MOBILE', 'DOB', 'QUAL', 'PROMOTION TO POST', 'PROMOTION FROM POST', 'CAT', 'SEAT CATEGORY', 'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON PROMOTION TO POST', 'DATE OF POSTING ON PROMOTION FROM POST', 'ROSTER POSITION', 'DEPUTATION DATE', 'DEPUTATION TO', 'DATE OF RETIREMENT'],
            self::FinalSeniorityListUs => ['SENIORITY NO', 'EMPLOYEE ID', 'EMPLOYEE NAME', 'DATE OF BIRTH', 'DATE OF APPOINTMENT', 'CURRENT POST', 'DATE OF POSTING ON CURRENT POST'],
            self::EvaluationChartUs => ['SN', 'EMPLOYEE NO', 'NAME OF US', 'MOBILE', 'DOB', 'QUAL', 'DOP', 'CAT', 'LOYALTY 2013 …', 'PROPERTY 2013 …', 'CR 2012 …', 'TRIBAL AREA 2012 …', 'TRIBAL AREA SERVICE YEARS', 'NOTE'],
        };
    }
}
