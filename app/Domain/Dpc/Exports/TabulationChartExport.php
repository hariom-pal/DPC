<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Exports;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * The Tabulation Chart as an Excel workbook (2026-11-18): the screen's columns, CR 1
 * to CR 5 with each year beneath, and Fit / Unfit as the cell's colour, not a word.
 */
final class TabulationChartExport extends DefaultValueBinder implements FromView, ShouldAutoSize, WithCustomValueBinder, WithTitle
{
    /** What a spreadsheet would read as the start of a formula (CSV / formula injection). */
    private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  list<int>  $crYears
     * @param  list<int>  $propertyYears
     */
    public function __construct(
        private readonly DpcProposal $proposal,
        private readonly array $crYears,
        private readonly array $propertyYears,
    ) {}

    public function view(): View
    {
        return view('dpc-proposals.tabulation-chart-export', [
            'proposal' => $this->proposal,
            'crYears' => $this->crYears,
            'propertyYears' => $this->propertyYears,
        ]);
    }

    /**
     * Every value as written, never as a formula: a name or order number the
     * department typed that begins with "=" (or + - @) stays text in the workbook.
     */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::FORMULA_LEADS, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Tabulation Chart';
    }
}
