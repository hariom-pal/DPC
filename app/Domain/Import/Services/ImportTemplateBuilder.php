<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

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

    public function filename(ImportTemplate $template, ?int $asOnYear = null): string
    {
        $suffix = $template->hasYearColumns()
            ? '-'.($asOnYear ?? now()->format('Y'))
            : '';

        return 'dpc-template-'.str_replace('_', '-', $template->value).$suffix.'.csv';
    }
}
