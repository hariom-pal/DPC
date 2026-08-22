<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Import\Enums\ImportTemplate;

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
            ImportTemplate::CurrentlyWorkingUs => [
                'SN', 'EMPLOYEE NO', 'NAME', 'DOB', 'QUAL', 'CURRENT POST', 'FEEDER POST', 'CAT',
                'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON POST',
                'DATE OF POSTING ON FEEDER POST', 'ROSTER POSITION', 'DEPUTATION DATE',
            ],

            ImportTemplate::FinalSeniorityListUs => [
                'SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'CURRENT POST', 'FEEDER POST', 'CAT',
                'DATE OF JOINING IN SERVICE', 'DATE OF POSTING ON CURRENT POST', 'DATE OF POSTING ON US',
            ],

            ImportTemplate::EvaluationChartUs => [
                'SN', 'EMPLOYEE NO', 'NAME OF US', 'DOB', 'QUAL', 'DOP', 'CAT',
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

        $headers[] = 'TRIBAL AREA SERVICE YEARS';
        $headers[] = 'NOTE';

        return $headers;
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
    public function csv(ImportTemplate $template, ?int $asOnYear = null): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $this->headers($template, $asOnYear));
        rewind($handle);

        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
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
                $row->date_of_posting_on_feeder_post?->format('d-m-Y'),
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
