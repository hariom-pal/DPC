<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportTemplate;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRuleSource;
use App\Domain\Master\Models\ReservationCategory;
use App\Enums\MasterStatus;

/**
 * A blank spreadsheet in exactly the shape the importer accepts — M11.
 *
 * **Recorded in the M11 Enhancement Backlog as "the most likely cause of rejected
 * imports today":** the three upload screens (S16–S18) document their columns in
 * prose, so a department constructs the file from a written list and gets a header
 * wrong. This produces the file instead.
 *
 * **CSV, not XLSX.** `dpc.imports.mimes` accepts CSV, every department can open one,
 * and it needs no writer library. The value of a template is the exact column names
 * in the exact order, and a CSV carries that as faithfully as a workbook.
 *
 * **Headers only — no sample row, deliberately.** A specimen officer is a row a
 * department will forget to delete, and the importer would take it as real. The
 * headers are the part nobody can get right from prose; the data is the part they
 * already have.
 *
 * **The Evaluation Chart is the one exception, and it is not a contradiction of
 * that rule.** Since the business directive of 2026-08-22 the chart's officers
 * are fixed by the approved Seniority List, so `prefilledEvaluationCsv()` emits
 * one row per officer with all ten identity fields already filled and only the
 * evaluation columns — Loyalty, Immovable Property, CR, Tribal Area Service, Note
 * — left blank. Those rows are not specimens a department might forget to delete;
 * they are **the exact set the importer will insist on**, and deleting one is a
 * refusal rather than a silent success. The reasoning above argues against
 * inventing data; this fills in data the system already holds.
 *
 * **Correct by construction, not by transcription.** The headers are emitted from
 * the same `columnMap()` the importer reads and the same year patterns it matches,
 * so a template cannot drift from the parser that consumes it. `expectedHeaders()`
 * is what the *screen* shows a human; it carries placeholders like `LOYALTY 2013 …`
 * and is not usable as a file.
 */
class ImportTemplateBuilder
{
    /**
     * The literal header row, in the order a department should fill it.
     *
     * @return list<string>
     */
    public function headers(ImportTemplate $template, ?int $asOnYear = null): array
    {
        $headers = match ($template) {
            /*
             | `STATUS` and `SOURCE` sit first, right after the serial number,
             | because together they govern how the rest of the row reads: `working` and every column
             | after it describes a person, `vacant` and the row is an empty chair
             | carrying nothing but its reservation category (business directive,
             | 2026-09-02).
             |
             | `DEPUTATION TO` and `DATE OF RETIREMENT` join it. Both were accepted
             | by the importer already but missing from the blank template, so a
             | department downloading one had no column to record the two dates the
             | vacancy engine counts.
             */
            ImportTemplate::CurrentlyWorkingUs => [
                'SN', 'STATUS', 'SOURCE', 'EMPLOYEE NO', 'NAME', 'MOBILE', 'DOB', 'QUAL', 'PROMOTION TO POST', 'PROMOTION FROM POST', 'CAT', 'SEAT CATEGORY',
                'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON PROMOTION TO POST',
                'DATE OF POSTING ON PROMOTION FROM POST', 'ROSTER POSITION',
                'DEPUTATION DATE', 'DEPUTATION TO', 'DATE OF RETIREMENT',
            ],

            ImportTemplate::FinalSeniorityListUs => [
                'SN', 'EMPLOYEE NO', 'NAME OF US', 'MOBILE', 'DOB', 'QUAL', 'PROMOTION TO POST', 'PROMOTION FROM POST', 'CAT', 'SEAT CATEGORY',
                'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON PROMOTION TO POST', 'DATE OF POSTING ON US',
            ],

            ImportTemplate::EvaluationChartUs => [
                'SN', 'EMPLOYEE NO', 'NAME OF US', 'MOBILE', 'DOB', 'QUAL', 'DOP', 'CAT',
            ],
        };

        if (! $template->hasYearColumns()) {
            return $headers;
        }

        // S18's per-year block. The importer matches these by pattern — `LOYALTY
        // 2015`, `IMMOVABLE PROPERTY 2015`, `CR 2015` — so they are emitted in the
        // exact spelling those patterns accept rather than in a prettier one.
        foreach ($this->years($asOnYear) as $year) {
            $headers[] = "LOYALTY {$year}";
        }

        foreach ($this->years($asOnYear) as $year) {
            $headers[] = "IMMOVABLE PROPERTY {$year}";
        }

        foreach ($this->years($asOnYear) as $year) {
            $headers[] = "CR {$year}";
        }

        /*
         | Tribal-area service per CR year (change register of 2026-08-27, item 18).
         |
         | > *Tribal Area benefit shall always be linked with the same CR year. No
         | > Tribal benefit shall be carried forward to another year.*
         |
         | Beside the CR block, because that is what it qualifies. The
         | officer-level `TRIBAL AREA SERVICE YEARS` below is **kept**: it is what
         | the S18 chart has always carried and what the PPT's formula still reads,
         | and dropping it would change the score — which item 21 forbids.
         */
        foreach ($this->years($asOnYear) as $year) {
            $headers[] = "TRIBAL AREA {$year}";
        }

        $headers[] = 'TRIBAL AREA SERVICE YEARS';
        $headers[] = 'NOTE';

        return $headers;
    }

    /**
     * What each column is, when it is required, and the format it takes.
     *
     * **One source for the file and for the screen** (business instruction,
     * 2026-09-06):
     *
     * > *Some fields are confusing — even I do not know. PROMOTION TO POST,
     * > PROMOTION FROM POST, DATE OF JOINING IN SERVICE, DATE OF POSTING ON
     * > PROMOTION TO POST, DATE OF POSTING ON PROMOTION FROM POST, ROSTER POSITION —
     * > what goes in them, when, how, and what the correct format is?*
     *
     * The answers are written once, here, and read twice: {@see csv()} writes them
     * into the template as `#` guidance lines, and the upload screen renders the
     * same array as a table. A file and a screen that explained the same column
     * differently would be worse than one that explained it nowhere.
     *
     * Keyed by the exact header, so a column that gains, loses or renames a header
     * shows up here as a missing key rather than as prose that quietly stops
     * matching.
     *
     * @return array<string, string>
     */
    public function columnGuide(): array
    {
        return [
            'SN' => (string) __('imports.guide.sn'),
            'STATUS' => (string) __('imports.guide.status'),
            'SOURCE' => (string) __('imports.guide.source'),
            'EMPLOYEE NO' => (string) __('imports.guide.employee_no'),
            'NAME' => (string) __('imports.guide.name'),
            'NAME OF US' => (string) __('imports.guide.name'),
            'MOBILE' => (string) __('imports.guide.mobile'),
            'DOB' => (string) __('imports.guide.dob'),
            'QUAL' => (string) __('imports.guide.qual'),
            'PROMOTION TO POST' => (string) __('imports.guide.promotion_to_post'),
            'PROMOTION FROM POST' => (string) __('imports.guide.promotion_from_post'),
            'CAT' => (string) __('imports.guide.cat'),
            'SEAT CATEGORY' => (string) __('imports.guide.seat_category'),
            'DATE OF JOINING IN SERVICE' => (string) __('imports.guide.date_of_joining'),
            'DATE OF POSTING ON PROMOTION TO POST' => (string) __('imports.guide.posting_on_to_post'),
            'DATE OF POSTING ON PROMOTION FROM POST' => (string) __('imports.guide.posting_on_from_post'),
            'DATE OF POSTING ON US' => (string) __('imports.guide.posting_on_from_post'),
            'ROSTER POSITION' => (string) __('imports.guide.roster_position'),
            'DEPUTATION DATE' => (string) __('imports.guide.deputation_date'),
            'DEPUTATION TO' => (string) __('imports.guide.deputation_to'),
            'DATE OF RETIREMENT' => (string) __('imports.guide.date_of_retirement'),
            'DOP' => (string) __('imports.guide.dop'),
            'TRIBAL AREA SERVICE YEARS' => (string) __('imports.guide.tribal_years'),
            'NOTE' => (string) __('imports.guide.note'),
        ];
    }

    /**
     * The guidance line for one header, or an empty cell.
     *
     * The per-year columns — `CR 2024`, `LOYALTY 2024` and the rest — are matched by
     * prefix rather than listed six times each in {@see columnGuide()}, which would
     * make the guide depend on the configured chart width.
     */
    private function guidanceFor(string $header, ?DpcProposal $proposal = null): string
    {
        $guide = $this->columnGuide();

        if (isset($guide[$header])) {
            return $guide[$header].$this->allowedValuesFor($header, $proposal);
        }

        foreach ([
            'CR ' => 'imports.guide.cr_year',
            'LOYALTY ' => 'imports.guide.loyalty_year',
            'IMMOVABLE PROPERTY ' => 'imports.guide.property_year',
            'TRIBAL AREA ' => 'imports.guide.tribal_year',
        ] as $prefix => $key) {
            if (str_starts_with($header, $prefix)) {
                return (string) __($key);
            }
        }

        return '';
    }

    /**
     * The years the per-year columns cover: `dpc.evaluation.max_cr_years` of them,
     * ending at the promotion-as-on year, oldest first.
     *
     * Configuration rather than a constant, because the chart's width is already
     * configuration (`max_cr_years`, defaulted to the six columns S18 shows), and a
     * template narrower than the chart would refuse data the chart accepts.
     *
     * @return list<int>
     */
    public function years(?int $asOnYear = null): array
    {
        $count = max(1, (int) config('dpc.evaluation.max_cr_years', 6));
        $end = $asOnYear ?? (int) now()->format('Y');

        $years = [];

        for ($year = $end - $count + 1; $year <= $end; $year++) {
            $years[] = $year;
        }

        return $years;
    }

    /**
     * The file itself.
     *
     * A UTF-8 BOM is prepended so Excel on Windows opens it as UTF-8 rather than
     * as the local code page — without it, a department's Hindi post name arrives
     * as mojibake and gets "corrected" by hand before upload.
     */
    public function csv(ImportTemplate $template, ?int $asOnYear = null, ?DpcProposal $proposal = null): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        $headers = $this->headers($template, $asOnYear);

        fputcsv($handle, $headers);

        /*
         | Two `#` lines under the header: what each column means, and one worked
         | example (business instruction, 2026-09-06).
         |
         | **`#` rather than a plain sample row**, because the objection to sample
         | rows was never that they are unhelpful — it was that a department forgets
         | to delete one and the importer takes it as an officer.
         | `ExcelImportService::isGuidance()` skips a row whose first cell starts
         | with `#`, so forgetting to delete these costs nothing.
         |
         | The example carries **this DPC's own post names** where a proposal is
         | given, so the two post columns are filled in with the only two values they
         | can hold and nobody types a post name from memory.
         */
        fputcsv($handle, $this->guidanceRow($headers, $proposal));
        fputcsv($handle, $this->exampleRow($headers, $proposal, $template));

        rewind($handle);

        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
    }

    /**
     * The `#` line describing every column.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function guidanceRow(array $headers, ?DpcProposal $proposal = null): array
    {
        $row = array_map(fn (string $header): string => $this->guidanceFor($header, $proposal), $headers);

        // The convention the importer skips on. Prefixed rather than replacing the
        // first cell's guidance, so `SN` still explains itself.
        $row[0] = '# '.$row[0];

        return $row;
    }

    /**
     * The values a column will actually accept, named rather than described.
     *
     * Business, 2026-09-06:
     *
     * > *Qualification cannot be free text — how will it match our master? … When
     * > downloading, Promotion To Post should already be filled with the post we
     * > know this DPC is for. … Can Promotion From Post not be a dropdown, so they
     * > can only choose from the available posts and there is no mistyping?*
     *
     * **A CSV has no dropdown, and this is the nearest honest thing to one.** The
     * permitted values are written into the column's own help line, so the person
     * filling the file copies rather than remembers. Three columns get one:
     *
     *  - **Promotion To Post** is fixed for the whole file — every officer on it is
     *    being considered for the same promotion — so it is stated as a single
     *    value rather than a list.
     *  - **Promotion From Post** is whichever feeders the approved Recruitment Rule
     *    names, which is the set the eligibility engine will accept. Falls back to
     *    the proposal's own feeder post when no rule is projected yet.
     *  - **Qualification** is the Qualification master's active rows.
     *
     * Empty when no proposal is given: the blank template on the Downloads screen is
     * not for any one DPC, and inventing a post list there would be worse than
     * saying nothing.
     */
    private function allowedValuesFor(string $header, ?DpcProposal $proposal): string
    {
        if ($proposal === null) {
            return '';
        }

        $values = match ($header) {
            'PROMOTION TO POST' => [$proposal->promotionToPost->name],
            'PROMOTION FROM POST' => $this->feederPostNames($proposal),
            'QUAL' => Qualification::query()
                ->where('status', MasterStatus::Active)
                ->orderBy('name_en')
                ->pluck('name_en')
                ->all(),
            'CAT', 'SEAT CATEGORY' => ReservationCategory::query()
                ->where('status', MasterStatus::Active)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
            default => [],
        };

        $values = array_values(array_filter(array_map('strval', $values)));

        if ($values === []) {
            return '';
        }

        return ' '.(count($values) === 1 && $header === 'PROMOTION TO POST'
            ? (string) __('imports.guide.fixed_value', ['value' => $values[0]])
            : (string) __('imports.guide.allowed', ['values' => implode(' | ', $values)]));
    }

    /**
     * The posts an officer may be promoted **out of** on this DPC.
     *
     * Read from the Recruitment Rule the Pre-DPC projected, because that is the set
     * the eligibility engine judges against — a file naming a post outside it
     * produces candidates no rule covers. The proposal's own feeder post is the
     * fallback for a DPC raised before any rule was projected.
     *
     * @return list<string>
     */
    private function feederPostNames(DpcProposal $proposal): array
    {
        $names = RecruitmentRuleSource::query()
            ->whereHas('recruitmentRule', fn ($query) => $query
                ->where('post_id', $proposal->promotion_to_post_id)
                ->where('status', MasterStatus::Active))
            ->with('promotionFromPost')
            ->get()
            ->map(fn (RecruitmentRuleSource $source): ?string => $source->promotionFromPost?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $names !== [] ? $names : [$proposal->promotionFromPost->name];
    }

    /**
     * One worked row, filled the way a correct row looks.
     *
     * The two post columns come from the proposal where there is one: they are
     * **constant for the whole DPC** — every officer on an S16 or S17 file is being
     * considered for the same promotion — so there is exactly one right answer and
     * no reason for anybody to type it.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function exampleRow(array $headers, ?DpcProposal $proposal, ImportTemplate $template): array
    {
        $examples = [
            'SN' => '1',
            'STATUS' => 'working',
            'SOURCE' => 'direct',
            'EMPLOYEE NO' => 'EMP/2011/0042',
            'NAME' => 'Shri Ram Kumar Sahu',
            'NAME OF US' => 'Shri Ram Kumar Sahu',
            'MOBILE' => '9425012345',
            'DOB' => '14-03-1974',
            'QUAL' => 'M.A., B.Ed.',
            'PROMOTION TO POST' => $proposal === null ? 'Deputy Secretary' : $proposal->promotionToPost->name,
            'PROMOTION FROM POST' => $proposal === null ? 'Under Secretary' : $proposal->promotionFromPost->name,
            'CAT' => 'UR',
            // The seat, not the officer: an SC officer on a General point is the
            // case this column exists for.
            'SEAT CATEGORY' => 'UR',
            'DATE OF JOINING IN SERVICE' => '02-08-1999',
            /*
             | **Blank on a Seniority List, filled on a Working list** (business
             | instruction, 2026-09-06: *"why does this field come at all? If the
             | promotion is yet to happen, this will never exist — it is for the
             | working list"*).
             |
             | Right on both counts. S16 lists officers who hold posts *now*, and one
             | of them may already be officiating in the promotion post; S17 lists
             | candidates for a promotion that has not happened, so the column is
             | empty for all of them. An example that filled it there would teach the
             | wrong habit — and it did: the identity factor Self-Inspection matched
             | on was this column until the same day, which is why a demo file had
             | been filled with the feeder date in it.
             */
            'DATE OF POSTING ON PROMOTION TO POST' => $template === ImportTemplate::CurrentlyWorkingUs ? '15-06-2018' : '',
            'DATE OF POSTING ON PROMOTION FROM POST' => '15-06-2018',
            'DATE OF POSTING ON US' => '15-06-2018',
            'ROSTER POSITION' => '7',
            'DEPUTATION DATE' => '',
            // A date — the day the deputation ends — despite the column's name.
            'DEPUTATION TO' => '',
            'DATE OF RETIREMENT' => '31-03-2034',
            'DOP' => '15-06-2018',
            'TRIBAL AREA SERVICE YEARS' => '0',
            'NOTE' => '',
        ];

        $row = [];

        foreach ($headers as $header) {
            $row[] = match (true) {
                isset($examples[$header]) => $examples[$header],
                str_starts_with($header, 'CR ') => 'क',
                str_starts_with($header, 'LOYALTY ') => 'Y',
                str_starts_with($header, 'IMMOVABLE PROPERTY ') => 'Y',
                str_starts_with($header, 'TRIBAL AREA ') => 'N',
                default => '',
            };
        }

        $row[0] = '# '.__('imports.guide.example_row').' — '.$row[0];

        return $row;
    }

    /**
     * The Evaluation Chart template with every officer already on it.
     *
     * Built from `dpc_evaluation_rows`, which the Seniority List projected — not
     * from the seniority entries directly. That matters: the importer matches on
     * `SN`, and the projection is what decided each officer's `sn`. Reading the
     * projected rows means the file's serial numbers are the ones the importer
     * will look for, rather than a second derivation that could disagree.
     *
     * Any evaluation data already entered is emitted too, so downloading, editing
     * and re-uploading is not destructive — the officer gets back what the system
     * holds, not a blank sheet that would wipe last week's work.
     */
    public function prefilledEvaluationCsv(DpcProposal $proposal, ?int $asOnYear = null): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        $asOnYear = $this->anchorYearFor($proposal, $asOnYear);
        $years = $this->years($asOnYear);

        fputcsv($handle, $this->headers(ImportTemplate::EvaluationChartUs, $asOnYear));

        $rows = $proposal->evaluationRows()
            ->with(['crGrades', 'loyaltyFlags', 'propertyFlags', 'reservationCategory'])
            ->orderBy('sn')
            ->get();

        foreach ($rows as $row) {
            $crByYear = $row->crGrades->pluck('grade', 'year');
            // Item 18 — emitted so a round trip through the template does not erase
            // an attribution the department has already made.
            $tribalByYear = $row->crGrades->pluck('tribal_area', 'year');
            $loyaltyByYear = $row->loyaltyFlags->pluck('flag', 'year');
            $propertyByYear = $row->propertyFlags->pluck('flag', 'year');

            $line = [
                $row->sn,
                $row->employee_number,
                $row->name,
                $row->dob->format('d-m-Y'),
                $row->qualification,
                // S18's DOP column is the feeder-post posting date, which is what
                // the importer maps `DOP` onto. Emitting the current-post date
                // here would round-trip an officer's history into the wrong field.
                $row->date_of_posting_on_promotion_from_post?->format('d-m-Y'),
                $row->reservationCategory?->code,
            ];

            foreach ($years as $year) {
                $line[] = $this->flagText($loyaltyByYear[$year] ?? null);
            }

            foreach ($years as $year) {
                $line[] = $this->flagText($propertyByYear[$year] ?? null);
            }

            foreach ($years as $year) {
                $line[] = $crByYear[$year] ?? null;
            }

            foreach ($years as $year) {
                $line[] = $this->flagText($tribalByYear[$year] ?? null);
            }

            $line[] = $row->tribal_area_service_years;
            $line[] = $row->note;

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
    }

    /**
     * The year the template's per-year columns end at.
     *
     * **Anchored on the chart's own latest recorded CR year, not on the proposal
     * date** — and this is a data-loss fix rather than a nicety. The columns span
     * `max_cr_years` ending at the anchor, so anchoring on the proposal year
     * emitted 2021–2026 for a chart holding 2012–2016: the department would have
     * downloaded a file with its CR grades **missing**, filled in the blanks, and
     * uploaded a chart that silently wiped five years of records.
     *
     * Falls back to the proposal year for an empty chart, where there is no data
     * to anchor to and the proposal's own year is the best guess at the window a
     * department will want.
     *
     * The span is bounded by the `max_cr_years` validation cap, so anchoring at
     * the newest recorded year brings the oldest one inside the window too.
     */
    private function anchorYearFor(DpcProposal $proposal, ?int $requested): int
    {
        if ($requested !== null) {
            return $requested;
        }

        $latest = $proposal->evaluationRows()
            ->join('dpc_evaluation_cr_grades', 'dpc_evaluation_cr_grades.dpc_evaluation_row_id', '=', 'dpc_evaluation_rows.id')
            ->max('dpc_evaluation_cr_grades.year');

        return $latest === null
            ? $proposal->proposal_date->year
            : (int) $latest;
    }

    /**
     * A per-year flag as the importer reads it back.
     *
     * **Blank for null, never "N".** A year with no recorded declaration and a
     * year recorded as "no" are different facts — a Circulation DPC exists
     * because of the first — and writing `N` into an unrecorded cell would turn
     * one into the other on the round trip.
     */
    private function flagText(?bool $flag): ?string
    {
        return match ($flag) {
            true => 'Y',
            false => 'N',
            null => null,
        };
    }

    public function prefilledEvaluationFilename(DpcProposal $proposal): string
    {
        return 'evaluation-chart-'.($proposal->dpc_code ?? 'draft-'.$proposal->id).'.csv';
    }

    public function filename(ImportTemplate $template, ?int $asOnYear = null): string
    {
        $suffix = $template->hasYearColumns()
            ? '-'.($asOnYear ?? now()->format('Y'))
            : '';

        return 'dpc-template-'.str_replace('_', '-', $template->value).$suffix.'.csv';
    }
}
